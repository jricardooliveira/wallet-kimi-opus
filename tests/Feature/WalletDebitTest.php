<?php

namespace Tests\Feature;

use App\Wallet;
use App\WalletTransaction;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Ramsey\Uuid\Uuid;

class WalletDebitTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create a wallet with the given balance and currency.
     *
     * @param  string  $balance
     * @param  string  $currency
     * @return \App\Wallet
     */
    private function createWallet($balance = '100.0000', $currency = 'EUR')
    {
        return Wallet::create([
            'id' => Uuid::uuid4()->toString(),
            'currency' => $currency,
            'balance' => $balance,
        ]);
    }

    /**
     * Build the debit request payload.
     *
     * @param  array  $overrides
     * @return array
     */
    private function payload(array $overrides = [])
    {
        return array_merge([
            'amount' => '10.05',
            'currency' => 'EUR',
            'reference' => 'order-4471',
        ], $overrides);
    }

    /** @test */
    public function it_debits_a_wallet_and_returns_the_transaction()
    {
        $wallet = $this->createWallet('100.0000');

        $response = $this->postJson(
            "/api/wallets/{$wallet->id}/debit",
            $this->payload(),
            ['Idempotency-Key' => 'key-1']
        );

        $response->assertStatus(200)
            ->assertJsonStructure([
                'transaction_id',
                'wallet_id',
                'amount',
                'currency',
                'balance_after',
                'reference',
            ])
            ->assertJson([
                'wallet_id' => $wallet->id,
                'amount' => '10.05',
                'currency' => 'EUR',
                'balance_after' => '89.9500',
                'reference' => 'order-4471',
            ]);

        $this->assertDatabaseHas('wallets', [
            'id' => $wallet->id,
            'balance' => '89.9500',
        ]);

        $this->assertEquals(1, WalletTransaction::count());
    }

    /** @test */
    public function it_maintains_exact_decimal_precision()
    {
        $wallet = $this->createWallet('1.0000');

        for ($i = 0; $i < 3; $i++) {
            $this->postJson(
                "/api/wallets/{$wallet->id}/debit",
                $this->payload(['amount' => '0.10', 'reference' => "order-{$i}"]),
                ['Idempotency-Key' => "precision-key-{$i}"]
            )->assertStatus(200);
        }

        $wallet->refresh();
        $this->assertEquals('0.7000', $wallet->balance);
    }

    /** @test */
    public function it_rejects_zero_amount()
    {
        $wallet = $this->createWallet();

        $response = $this->postJson(
            "/api/wallets/{$wallet->id}/debit",
            $this->payload(['amount' => '0']),
            ['Idempotency-Key' => 'key-zero']
        );

        $response->assertStatus(422);
        $wallet->refresh();
        $this->assertEquals('100.0000', $wallet->balance);
    }

    /** @test */
    public function it_rejects_negative_amount()
    {
        $wallet = $this->createWallet();

        $response = $this->postJson(
            "/api/wallets/{$wallet->id}/debit",
            $this->payload(['amount' => '-5.00']),
            ['Idempotency-Key' => 'key-negative']
        );

        $response->assertStatus(422);
        $wallet->refresh();
        $this->assertEquals('100.0000', $wallet->balance);
    }

    /** @test */
    public function it_rejects_non_numeric_amount()
    {
        $wallet = $this->createWallet();

        $response = $this->postJson(
            "/api/wallets/{$wallet->id}/debit",
            $this->payload(['amount' => 'abc']),
            ['Idempotency-Key' => 'key-nan']
        );

        $response->assertStatus(422);
        $wallet->refresh();
        $this->assertEquals('100.0000', $wallet->balance);
    }

    /** @test */
    public function it_rejects_too_many_fractional_digits()
    {
        $wallet = $this->createWallet();

        $response = $this->postJson(
            "/api/wallets/{$wallet->id}/debit",
            $this->payload(['amount' => '10.00005']),
            ['Idempotency-Key' => 'key-digits']
        );

        $response->assertStatus(422);
        $wallet->refresh();
        $this->assertEquals('100.0000', $wallet->balance);
    }

    /** @test */
    public function it_rejects_currency_mismatch_without_changing_balance()
    {
        $wallet = $this->createWallet('100.0000', 'EUR');

        $response = $this->postJson(
            "/api/wallets/{$wallet->id}/debit",
            $this->payload(['currency' => 'USD']),
            ['Idempotency-Key' => 'key-currency']
        );

        $response->assertStatus(422);
        $wallet->refresh();
        $this->assertEquals('100.0000', $wallet->balance);
        $this->assertEquals(0, WalletTransaction::count());
    }

    /** @test */
    public function it_returns_insufficient_funds_and_leaves_balance_unchanged()
    {
        $wallet = $this->createWallet('5.0000');

        $response = $this->postJson(
            "/api/wallets/{$wallet->id}/debit",
            $this->payload(['amount' => '10.00']),
            ['Idempotency-Key' => 'key-funds']
        );

        $response->assertStatus(409)
            ->assertJson(['error' => 'insufficient_funds']);

        $wallet->refresh();
        $this->assertEquals('5.0000', $wallet->balance);
        $this->assertEquals(0, WalletTransaction::count());
    }

    /** @test */
    public function it_returns_the_original_transaction_on_idempotent_retry()
    {
        $wallet = $this->createWallet('100.0000');

        $first = $this->postJson(
            "/api/wallets/{$wallet->id}/debit",
            $this->payload(),
            ['Idempotency-Key' => 'key-retry']
        );

        $first->assertStatus(200);
        $transactionId = $first->json()['transaction_id'];

        $second = $this->postJson(
            "/api/wallets/{$wallet->id}/debit",
            $this->payload(),
            ['Idempotency-Key' => 'key-retry']
        );

        $second->assertStatus(200);
        $this->assertEquals($transactionId, $second->json()['transaction_id']);
        $this->assertEquals('89.9500', $second->json()['balance_after']);

        $wallet->refresh();
        $this->assertEquals('89.9500', $wallet->balance);
        $this->assertEquals(1, WalletTransaction::count());
    }

    /** @test */
    public function it_conflicts_when_idempotency_key_is_reused_with_different_body()
    {
        $wallet = $this->createWallet('100.0000');

        $first = $this->postJson(
            "/api/wallets/{$wallet->id}/debit",
            $this->payload(),
            ['Idempotency-Key' => 'key-conflict']
        );

        $first->assertStatus(200);

        $second = $this->postJson(
            "/api/wallets/{$wallet->id}/debit",
            $this->payload(['amount' => '20.00']),
            ['Idempotency-Key' => 'key-conflict']
        );

        $second->assertStatus(409)
            ->assertJson(['error' => 'idempotency_conflict']);

        $wallet->refresh();
        $this->assertEquals('89.9500', $wallet->balance);
        $this->assertEquals(1, WalletTransaction::count());
    }

    /** @test */
    public function it_requires_the_idempotency_key_header()
    {
        $wallet = $this->createWallet();

        $response = $this->postJson(
            "/api/wallets/{$wallet->id}/debit",
            $this->payload()
        );

        $response->assertStatus(422);
    }

    /** @test */
    public function it_returns_404_for_missing_wallet()
    {
        $response = $this->postJson(
            '/api/wallets/' . Uuid::uuid4()->toString() . '/debit',
            $this->payload(),
            ['Idempotency-Key' => 'key-missing']
        );

        $response->assertStatus(404);
    }

    /** @test */
    public function concurrent_debits_prevent_overdraft()
    {
        $databasePath = sys_get_temp_dir() . '/wallet_debit_concurrency_' . uniqid() . '.sqlite';
        touch($databasePath);

        config(['database.connections.sqlite.database' => $databasePath]);
        config(['database.default' => 'sqlite']);
        \DB::purge('sqlite');
        \DB::reconnect('sqlite');

        $this->artisan('migrate', ['--force' => true]);

        $wallet = Wallet::create([
            'id' => Uuid::uuid4()->toString(),
            'currency' => 'EUR',
            'balance' => '100.0000',
        ]);

        $port = rand(10000, 65000);
        $host = "127.0.0.1:{$port}";
        $command = sprintf(
            'APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=%s php artisan serve --host=127.0.0.1 --port=%d',
            escapeshellarg($databasePath),
            $port
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $server = proc_open($command, $descriptors, $pipes, base_path());

        try {
            $ready = false;
            for ($i = 0; $i < 50; $i++) {
                $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
                if ($socket) {
                    fclose($socket);
                    $ready = true;
                    break;
                }
                usleep(100000);
            }
            $this->assertTrue($ready, 'Server did not start in time');

            $payload = json_encode($this->payload(['amount' => '60.00']));
            $headers = [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($payload),
            ];

            $ch1 = curl_init("http://{$host}/api/wallets/{$wallet->id}/debit");
            curl_setopt($ch1, CURLOPT_POST, true);
            curl_setopt($ch1, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch1, CURLOPT_HTTPHEADER, array_merge($headers, ['Idempotency-Key: concurrent-1']));
            curl_setopt($ch1, CURLOPT_RETURNTRANSFER, true);

            $ch2 = curl_init("http://{$host}/api/wallets/{$wallet->id}/debit");
            curl_setopt($ch2, CURLOPT_POST, true);
            curl_setopt($ch2, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch2, CURLOPT_HTTPHEADER, array_merge($headers, ['Idempotency-Key: concurrent-2']));
            curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);

            $mh = curl_multi_init();
            curl_multi_add_handle($mh, $ch1);
            curl_multi_add_handle($mh, $ch2);

            $running = null;
            do {
                curl_multi_exec($mh, $running);
                curl_multi_select($mh);
            } while ($running > 0);

            $body1 = curl_multi_getcontent($ch1);
            $body2 = curl_multi_getcontent($ch2);
            $httpCode1 = curl_getinfo($ch1, CURLINFO_HTTP_CODE);
            $httpCode2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);

            curl_multi_remove_handle($mh, $ch1);
            curl_multi_remove_handle($mh, $ch2);
            curl_multi_close($mh);

            $successes = 0;
            $failures = 0;
            foreach ([$httpCode1, $httpCode2] as $code) {
                if ($code === 200) {
                    $successes++;
                } elseif ($code === 409) {
                    $failures++;
                }
            }

            $this->assertEquals(1, $successes, 'Exactly one debit should succeed. Responses: ' . $body1 . ', ' . $body2);
            $this->assertEquals(1, $failures, 'Exactly one debit should fail with insufficient funds.');

            $wallet->refresh();
            $this->assertEquals('40.0000', $wallet->balance);
            $this->assertEquals(1, WalletTransaction::count());
        } finally {
            proc_terminate($server);
            proc_close($server);
            @unlink($databasePath);
        }
    }
}
