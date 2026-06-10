# Request handling

In-game traffic flows: **route → thin controller → handler registry → version
handler**. Response-building logic never lives in the controller; it lives in the
handler layer, where divergence between versions is isolated to named classes.

## Routes

`routes/game.php` defines every cabinet endpoint. Game-protocol routes carry a
`{version}` segment constrained to the pattern `v[0-9]{2}r[0-9]{2}(?:_[a-z]{2})?`
(e.g. `v11r01`, `v01r00_tw`), so a single route line serves all versions:

```php
Route::post('{version}/chassis/playresult.php', [GameProtocolController::class, 'playResult'])
    ->where('version', $protocolVersionPattern);
```

A catch-all `fallback()` logs anything unrouted to `storage/logs/mucha.log` so new
cabinet probes can be discovered.

## Controller (thin)

`App\Http\Controllers\Green\GameProtocolController` does only routing concerns. Each
endpoint is a one-line dispatch:

```php
public function playResult(Request $request, string $version): Response
{
    return $this->dispatch($version, 'playResult', $request);
}

private function dispatch(string $routeVersion, string $method, Request $request): Response
{
    $game = TaikoGameVersion::fromRouteVersion($routeVersion) ?? TaikoGameVersion::Green;
    return $this->handlers->for($game)->{$method}($request, $game);
}
```

The bare `POST /` setup probe is the one exception: `rootSetup()` sniffs the raw
protobuf to decide whether it is a bookkeeping, telop, or initial-data-check request
before dispatching.

## Handler layer

`App\GameProtocol\Handlers\`:

- **`GameHandler`** — the default dialect. Holds one method per endpoint plus the
  shared collaborators (payloads, message resolver, writer, profile/play-result
  services, score mapper, cabinet service). Most versions use it unchanged.
- **`BlueGameHandler extends GameHandler`** — overrides only `initialDataCheck`,
  where Blue's response shape diverges, and carries the raw-protobuf builders that
  divergence needs. Everything else is inherited.
- **`GameHandlerRegistry`** — maps a version to its handler:

  ```php
  public function for(TaikoGameVersion $version): GameHandler
  {
      return $this->container->make(match ($version) {
          TaikoGameVersion::Blue => BlueGameHandler::class,
          default => GameHandler::class,
      });
  }
  ```

## Adding a version-specific behaviour

When a version needs a different response for one endpoint:

1. Create `XxxGameHandler extends GameHandler`.
2. Override only the divergent method(s).
3. Add the `match` arm in `GameHandlerRegistry`.

The eight versions that share the default behaviour stay untouched. This is the
intended place for the per-version play-result shape forks (e.g. a `RedGameHandler`
that reads an inline play result instead of the gzip blob).
