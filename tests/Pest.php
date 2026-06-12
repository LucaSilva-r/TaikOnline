<?php

use App\Enums\TaikoGameVersion;
use Google\Protobuf\Internal\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

beforeEach(function (): void {
    URL::defaults(['taikoVersion' => TaikoGameVersion::default()->value]);
});

function post_protobuf(string $uri, Message $request, string $responseClass): Message
{
    $response = post_protobuf_raw($uri, $request);

    $message = new $responseClass;
    $message->mergeFromString($response->getContent());

    return $message;
}

function post_protobuf_raw(string $uri, Message $request): TestResponse
{
    $response = test()->call(
        'POST',
        $uri,
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/protobuf', 'HTTP_ACCEPT' => 'application/protobuf'],
        $request->serializeToString(),
    );

    $response->assertOk();

    return $response;
}

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * @return array<int, string>
 */
function nbgic_test_profile_records(): array
{
    return collect(['300', '302', '303', '304', '305', '306', '307', '308'])
        ->map(function (string $prefix, int $index): string {
            $record = pack('N', $index)
                .$prefix
                ."\0"
                .pack('C*', ...range(0, 55))
                .pack('N2', 0, 0);

            expect(strlen($record))->toBe(0x48);

            return bin2hex($record);
        })
        ->all();
}

function configure_nbgic_test_profiles(): void
{
    config()->set('taiko_green.nbgic_profile_records', nbgic_test_profile_records());
}
