<?php

use App\Models\GameCard;
use App\Models\Player;
use App\Models\User;
use App\Services\CabinetPairingCodeGenerator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    Cache::flush();
    config()->set('taiko_green.zucchini_api_token_hashes', [hash('sha256', 'official-token')]);
});

function useCabinetPairingCodes(array $codes): void
{
    app()->instance(CabinetPairingCodeGenerator::class, new class($codes) extends CabinetPairingCodeGenerator
    {
        /**
         * @param  list<string>  $codes
         */
        public function __construct(private array $codes) {}

        public function generate(): string
        {
            return array_shift($this->codes) ?? '999999';
        }
    });
}

/**
 * @param  array<string, mixed>  $payload
 */
function pollCabinetPairing(array $payload): TestResponse
{
    return test()
        ->withHeader('Authorization', 'Bearer official-token')
        ->post('/api/zucchini/pairing', $payload);
}

/**
 * @return array<string, string>
 */
function cabinetPairingPayload(TestResponse $response): array
{
    $payload = [];
    parse_str(str_replace("\n", '&', trim($response->getContent())), $payload);

    return $payload;
}

function createPairingUser(string $accessCode): User
{
    $user = User::factory()->create();
    $player = Player::query()->create(['user_id' => $user->id]);
    GameCard::query()->create([
        'access_code' => $accessCode,
        'baid' => $player->baid,
    ]);

    return $user;
}

test('pairing api requires an official zucchini build token', function (): void {
    $this->post('/api/zucchini/pairing', [
        'cabinet_id' => '1234abcd',
        'state' => 'attract',
        'accepting' => '1',
    ])->assertUnauthorized();
});

test('pairing api validates its plain text protocol request', function (): void {
    pollCabinetPairing([
        'cabinet_id' => 'not-a-cabinet',
        'state' => 'attract',
        'accepting' => '1',
    ])->assertUnprocessable()
        ->assertHeader('Content-Type', 'text/plain; charset=utf-8');
});

test('cabinet receives a leading-zero six digit code and opaque session', function (): void {
    useCabinetPairingCodes(['000123']);

    $response = pollCabinetPairing([
        'cabinet_id' => '1234abcd',
        'state' => 'attract',
        'accepting' => '1',
    ])->assertOk();

    $payload = cabinetPairingPayload($response);

    expect($payload)
        ->status->toBe('active')
        ->code->toBe('000123')
        ->expires_in->toBe('30')
        ->and($payload['session'])->toMatch('/\A[A-Za-z0-9]{64}\z/');
});

test('active cabinets retry code collisions without overwriting another session', function (): void {
    useCabinetPairingCodes(['111111', '111111', '222222']);

    $first = cabinetPairingPayload(pollCabinetPairing([
        'cabinet_id' => '11111111',
        'state' => 'attract',
        'accepting' => '1',
    ])->assertOk());
    $second = cabinetPairingPayload(pollCabinetPairing([
        'cabinet_id' => '22222222',
        'state' => 'shop',
        'accepting' => '1',
    ])->assertOk());

    expect($first['code'])->toBe('111111')
        ->and($second['code'])->toBe('222222')
        ->and($second['session'])->not->toBe($first['session']);
});

test('a live session rotates its code after thirty seconds', function (): void {
    useCabinetPairingCodes(['123456', '654321']);

    $payload = cabinetPairingPayload(pollCabinetPairing([
        'cabinet_id' => '1234abcd',
        'state' => 'attract',
        'accepting' => '1',
    ])->assertOk());

    foreach ([8, 8, 8] as $seconds) {
        $this->travel($seconds)->seconds();
        pollCabinetPairing([
            'session' => $payload['session'],
            'cabinet_id' => '1234abcd',
            'state' => 'attract',
            'accepting' => '1',
        ])->assertOk();
    }

    $this->travel(7)->seconds();
    $rotated = cabinetPairingPayload(pollCabinetPairing([
        'session' => $payload['session'],
        'cabinet_id' => '1234abcd',
        'state' => 'attract',
        'accepting' => '1',
    ])->assertOk());

    expect($rotated['session'])->toBe($payload['session'])
        ->and($rotated['code'])->toBe('654321');
});

test('authenticated user can queue their existing banapass for a cabinet', function (): void {
    useCabinetPairingCodes(['123456']);
    $user = createPairingUser('30800000000000000001');
    $started = cabinetPairingPayload(pollCabinetPairing([
        'cabinet_id' => '1234abcd',
        'state' => 'attract',
        'accepting' => '1',
    ])->assertOk());

    $this->actingAs($user)
        ->post(route('play.store'), ['code' => '123456'])
        ->assertRedirect(route('play.create'))
        ->assertSessionHasNoErrors();

    $firstPoll = cabinetPairingPayload(pollCabinetPairing([
        'session' => $started['session'],
        'cabinet_id' => '1234abcd',
        'state' => 'attract',
        'accepting' => '1',
    ])->assertOk());
    $repeatedPoll = cabinetPairingPayload(pollCabinetPairing([
        'session' => $started['session'],
        'cabinet_id' => '1234abcd',
        'state' => 'attract',
        'accepting' => '1',
    ])->assertOk());

    expect($firstPoll['status'])->toBe('claimed')
        ->and($firstPoll['access_code'])->toBe('30800000000000000001')
        ->and($firstPoll['command_id'])->toBe($repeatedPoll['command_id'])
        ->and($repeatedPoll['access_code'])->toBe('30800000000000000001');

    $acknowledged = cabinetPairingPayload(pollCabinetPairing([
        'session' => $started['session'],
        'cabinet_id' => '1234abcd',
        'state' => 'attract',
        'accepting' => '1',
        'ack' => $firstPoll['command_id'],
    ])->assertOk());

    expect($acknowledged['status'])->toBe('complete')
        ->and($acknowledged)->not->toHaveKey('access_code');
});

test('only the first user can claim a displayed cabinet code', function (): void {
    useCabinetPairingCodes(['123456']);
    $firstUser = createPairingUser('30800000000000000001');
    $secondUser = createPairingUser('30800000000000000002');
    pollCabinetPairing([
        'cabinet_id' => '1234abcd',
        'state' => 'shop',
        'accepting' => '1',
    ])->assertOk();

    $this->actingAs($firstUser)
        ->post(route('play.store'), ['code' => '123456'])
        ->assertSessionHasNoErrors();

    $this->actingAs($secondUser)
        ->post(route('play.store'), ['code' => '123456'])
        ->assertSessionHasErrors('code');
});

test('closing the reader invalidates its displayed code', function (): void {
    useCabinetPairingCodes(['123456']);
    $user = createPairingUser('30800000000000000001');
    $started = cabinetPairingPayload(pollCabinetPairing([
        'cabinet_id' => '1234abcd',
        'state' => 'attract',
        'accepting' => '1',
    ])->assertOk());

    $closed = cabinetPairingPayload(pollCabinetPairing([
        'session' => $started['session'],
        'cabinet_id' => '1234abcd',
        'state' => 'entry',
        'accepting' => '0',
    ])->assertOk());

    expect($closed['status'])->toBe('closed');

    $this->actingAs($user)
        ->post(route('play.store'), ['code' => '123456'])
        ->assertSessionHasErrors('code');
});

test('play page requires authentication and renders the otp form for a linked account', function (): void {
    $this->get(route('play.create'))->assertRedirect(route('login'));

    $this->actingAs(createPairingUser('30800000000000000001'))
        ->get(route('play.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Play')
            ->where('hasUsableAccessCode', true));
});

test('play page shows the banapass prerequisite for an unlinked account', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('play.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Play')
            ->where('hasUsableAccessCode', false));
});

test('login explains why authentication is required when arriving from play', function (): void {
    $this->get(route('play.create'))->assertRedirect(route('login'));

    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('auth/Login')
            ->where('playIntent', true));
});

test('cabinet login rejects users without a usable existing card', function (): void {
    useCabinetPairingCodes(['123456']);
    $user = User::factory()->create();
    pollCabinetPairing([
        'cabinet_id' => '1234abcd',
        'state' => 'attract',
        'accepting' => '1',
    ])->assertOk();

    $this->actingAs($user)
        ->post(route('play.store'), ['code' => '123456'])
        ->assertSessionHasErrors('code');
});

test('cabinet login validates six ascii digits', function (mixed $code): void {
    $this->actingAs(User::factory()->create())
        ->post(route('play.store'), ['code' => $code])
        ->assertSessionHasErrors('code');
})->with([
    'missing' => null,
    'short' => '12345',
    'long' => '1234567',
    'letters' => '12A456',
    'unicode digits' => '１２３４５６',
]);
