#include <arpa/inet.h>
#include <ctype.h>
#include <errno.h>
#include <gnutls/gnutls.h>
#include <netdb.h>
#include <netinet/in.h>
#include <pthread.h>
#include <signal.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/select.h>
#include <sys/socket.h>
#include <unistd.h>

#define BUFFER_SIZE 16384
#define MAX_PORTS 16

/*
 * Per-port TLS credentials. Each listening port loads its own (cert, key)
 * pair so the proxy can present a different certificate chain depending on
 * which embedded CA the cabinet is going to validate against.
 *
 * The default path layout, populated by scripts/generate-mucha-certs.sh:
 *
 *   /certificates/generated/mucha/leaf.pem    (chain: leaf + ca)
 *   /certificates/generated/mucha/leaf.key
 *   /certificates/generated/vsapi/leaf.pem
 *   /certificates/generated/vsapi/leaf.key
 *
 * Per-port assignments come from env vars CERT_<port>_CHAIN and
 * CERT_<port>_KEY. Ports without an explicit override fall back to the
 * legacy single-cert pair at /tmp/taiko-cert.{crt,key} that start.sh
 * extracts from the existing cert.pfx.
 */

struct port_cred {
    int port;
    gnutls_certificate_credentials_t creds;
    char *chain_path;
    char *key_path;
};

static struct port_cred port_creds[MAX_PORTS];
static int port_creds_count = 0;
static gnutls_certificate_credentials_t fallback_creds;
static int fallback_loaded = 0;

static const char *priority = "NORMAL:-VERS-ALL:+VERS-TLS1.0:+RSA:+AES-128-CBC:+AES-256-CBC:+3DES-CBC:+ARCFOUR-128:+SHA1:+SHA256:+SIGN-RSA-SHA1:+SIGN-RSA-SHA256:+COMP-NULL:%COMPAT";
static const char *backend_host = "laravel.test";
static const char *backend_port = "80";

struct client_args {
    int fd;
    int listen_port;
};

static gnutls_certificate_credentials_t creds_for_port(int port) {
    for (int i = 0; i < port_creds_count; i++) {
        if (port_creds[i].port == port) return port_creds[i].creds;
    }
    return fallback_loaded ? fallback_creds : NULL;
}

static int send_all_plain(int fd, const char *buf, size_t len) {
    size_t off = 0;
    while (off < len) {
        ssize_t n = send(fd, buf + off, len - off, MSG_NOSIGNAL);
        if (n <= 0) return -1;
        off += (size_t)n;
    }
    return 0;
}

static int send_all_tls(gnutls_session_t session, const char *buf, size_t len) {
    size_t off = 0;
    while (off < len) {
        ssize_t n = gnutls_record_send(session, buf + off, len - off);
        if (n < 0) return -1;
        off += (size_t)n;
    }
    return 0;
}

static int connect_backend(void) {
    struct addrinfo hints;
    struct addrinfo *result = NULL;
    memset(&hints, 0, sizeof(hints));
    hints.ai_family = AF_UNSPEC;
    hints.ai_socktype = SOCK_STREAM;

    if (getaddrinfo(backend_host, backend_port, &hints, &result) != 0) {
        return -1;
    }

    int fd = -1;
    for (struct addrinfo *rp = result; rp != NULL; rp = rp->ai_next) {
        fd = socket(rp->ai_family, rp->ai_socktype, rp->ai_protocol);
        if (fd < 0) continue;
        if (connect(fd, rp->ai_addr, rp->ai_addrlen) == 0) break;
        close(fd);
        fd = -1;
    }

    freeaddrinfo(result);
    return fd;
}

static void *handle_client(void *argp) {
    struct client_args *args = (struct client_args *)argp;
    int client_fd = args->fd;
    int listen_port = args->listen_port;
    free(args);

    int backend_fd = -1;
    gnutls_session_t session;
    char buffer[BUFFER_SIZE];

    gnutls_certificate_credentials_t creds = creds_for_port(listen_port);
    if (!creds) {
        fprintf(stderr, "no credentials registered for port %d\n", listen_port);
        close(client_fd);
        return NULL;
    }

    gnutls_init(&session, GNUTLS_SERVER);
    gnutls_priority_set_direct(session, priority, NULL);
    gnutls_credentials_set(session, GNUTLS_CRD_CERTIFICATE, creds);
    gnutls_transport_set_int(session, client_fd);

    int ret = gnutls_handshake(session);
    if (ret < 0) {
        fprintf(stderr, "TLS handshake failed on %d: %s\n", listen_port, gnutls_strerror(ret));
        goto done;
    }

    fprintf(stderr, "TLS connected on %d: %s\n", listen_port, gnutls_session_get_desc(session));

    backend_fd = connect_backend();
    if (backend_fd < 0) {
        perror("connect backend");
        goto done;
    }

    int client_open = 1;
    int backend_open = 1;

    while (backend_open) {
        fd_set rfds;
        FD_ZERO(&rfds);
        if (client_open) FD_SET(client_fd, &rfds);
        FD_SET(backend_fd, &rfds);
        int max_fd = client_fd > backend_fd ? client_fd : backend_fd;

        ret = select(max_fd + 1, &rfds, NULL, NULL, NULL);
        if (ret <= 0) break;

        if (client_open && (FD_ISSET(client_fd, &rfds) || gnutls_record_check_pending(session) > 0)) {
            ssize_t n = gnutls_record_recv(session, buffer, sizeof(buffer));
            if (n == 0) {
                client_open = 0;
                shutdown(backend_fd, SHUT_WR);
                continue;
            }
            if (n < 0) break;
            if (send_all_plain(backend_fd, buffer, (size_t)n) != 0) break;
        }

        if (FD_ISSET(backend_fd, &rfds)) {
            ssize_t n = recv(backend_fd, buffer, sizeof(buffer), 0);
            if (n == 0) {
                backend_open = 0;
                break;
            }
            if (n < 0) break;
            if (send_all_tls(session, buffer, (size_t)n) != 0) break;
        }
    }

done:
    if (backend_fd >= 0) close(backend_fd);
    gnutls_bye(session, GNUTLS_SHUT_WR);
    gnutls_deinit(session);
    close(client_fd);
    return NULL;
}

static int listen_on(int port) {
    int fd = socket(AF_INET, SOCK_STREAM, 0);
    if (fd < 0) return -1;

    int yes = 1;
    setsockopt(fd, SOL_SOCKET, SO_REUSEADDR, &yes, sizeof(yes));

    struct sockaddr_in addr;
    memset(&addr, 0, sizeof(addr));
    addr.sin_family = AF_INET;
    addr.sin_addr.s_addr = htonl(INADDR_ANY);
    addr.sin_port = htons((uint16_t)port);

    if (bind(fd, (struct sockaddr *)&addr, sizeof(addr)) != 0) {
        close(fd);
        return -1;
    }
    if (listen(fd, 64) != 0) {
        close(fd);
        return -1;
    }
    return fd;
}

static int parse_ports(const char *value, int *ports) {
    char *copy = strdup(value);
    int count = 0;
    char *token = strtok(copy, ",");
    while (token != NULL && count < MAX_PORTS) {
        ports[count++] = atoi(token);
        token = strtok(NULL, ",");
    }
    free(copy);
    return count;
}

/*
 * Read CERT_<port>_CHAIN / CERT_<port>_KEY from the environment for a given
 * port. Returns 1 if both are set (and non-empty), 0 otherwise. The two
 * output buffers must be at least 256 bytes.
 */
static int env_cert_for_port(int port, char *chain_out, char *key_out) {
    char chain_var[64];
    char key_var[64];
    snprintf(chain_var, sizeof(chain_var), "CERT_%d_CHAIN", port);
    snprintf(key_var, sizeof(key_var), "CERT_%d_KEY", port);

    const char *chain = getenv(chain_var);
    const char *key = getenv(key_var);
    if (!chain || !*chain || !key || !*key) return 0;

    snprintf(chain_out, 256, "%s", chain);
    snprintf(key_out, 256, "%s", key);
    return 1;
}

static int load_creds_for_port(int port) {
    char chain_path[256];
    char key_path[256];

    if (!env_cert_for_port(port, chain_path, key_path)) {
        if (!fallback_loaded) {
            fprintf(stderr, "no CERT_%d_CHAIN/CERT_%d_KEY set and no fallback creds loaded\n", port, port);
            return -1;
        }
        fprintf(stderr, "port %d using fallback (legacy cert.pfx) creds\n", port);
        return 0;
    }

    if (port_creds_count >= MAX_PORTS) return -1;

    gnutls_certificate_credentials_t creds;
    if (gnutls_certificate_allocate_credentials(&creds) != 0) return -1;

    int rc = gnutls_certificate_set_x509_key_file(creds, chain_path, key_path, GNUTLS_X509_FMT_PEM);
    if (rc < 0) {
        fprintf(stderr, "port %d: failed to load %s + %s: %s\n", port, chain_path, key_path, gnutls_strerror(rc));
        gnutls_certificate_free_credentials(creds);
        return -1;
    }

    port_creds[port_creds_count].port = port;
    port_creds[port_creds_count].creds = creds;
    port_creds[port_creds_count].chain_path = strdup(chain_path);
    port_creds[port_creds_count].key_path = strdup(key_path);
    port_creds_count++;

    fprintf(stderr, "port %d: loaded TLS chain=%s key=%s\n", port, chain_path, key_path);
    return 0;
}

int main(void) {
    signal(SIGPIPE, SIG_IGN);

    const char *env_backend_host = getenv("BACKEND_HOST");
    const char *env_backend_port = getenv("BACKEND_PORT");
    const char *env_ports = getenv("LISTEN_PORTS");
    if (env_backend_host && strlen(env_backend_host) > 0) backend_host = env_backend_host;
    if (env_backend_port && strlen(env_backend_port) > 0) backend_port = env_backend_port;
    if (!env_ports || strlen(env_ports) == 0) env_ports = "10122,54430,54431,57402,443";

    gnutls_global_init();

    /* Optional fallback creds from the legacy single-pfx start.sh path. */
    const char *fallback_chain = "/tmp/taiko-cert.crt";
    const char *fallback_key = "/tmp/taiko-cert.key";
    if (access(fallback_chain, R_OK) == 0 && access(fallback_key, R_OK) == 0) {
        if (gnutls_certificate_allocate_credentials(&fallback_creds) == 0
            && gnutls_certificate_set_x509_key_file(fallback_creds, fallback_chain, fallback_key, GNUTLS_X509_FMT_PEM) == 0) {
            fallback_loaded = 1;
            fprintf(stderr, "fallback creds loaded from %s + %s\n", fallback_chain, fallback_key);
        } else {
            fprintf(stderr, "fallback cred load failed; per-port CERT_<port>_CHAIN/KEY required\n");
        }
    } else {
        fprintf(stderr, "no fallback cert at %s; per-port CERT_<port>_CHAIN/KEY required\n", fallback_chain);
    }

    int ports[MAX_PORTS];
    int count = parse_ports(env_ports, ports);
    int fds[MAX_PORTS];

    for (int i = 0; i < count; i++) {
        if (load_creds_for_port(ports[i]) != 0) return 1;
        fds[i] = listen_on(ports[i]);
        if (fds[i] < 0) {
            fprintf(stderr, "listen on %d failed: %s\n", ports[i], strerror(errno));
            return 1;
        }
        fprintf(stderr, "Legacy TLS proxy listening on %d -> %s:%s\n", ports[i], backend_host, backend_port);
    }

    for (;;) {
        fd_set rfds;
        FD_ZERO(&rfds);
        int max_fd = 0;
        for (int i = 0; i < count; i++) {
            FD_SET(fds[i], &rfds);
            if (fds[i] > max_fd) max_fd = fds[i];
        }

        if (select(max_fd + 1, &rfds, NULL, NULL, NULL) <= 0) continue;

        for (int i = 0; i < count; i++) {
            if (!FD_ISSET(fds[i], &rfds)) continue;
            int client_fd = accept(fds[i], NULL, NULL);
            if (client_fd < 0) continue;

            struct client_args *args = calloc(1, sizeof(*args));
            args->fd = client_fd;
            args->listen_port = ports[i];

            pthread_t thread;
            if (pthread_create(&thread, NULL, handle_client, args) == 0) {
                pthread_detach(thread);
            } else {
                close(client_fd);
                free(args);
            }
        }
    }
}
