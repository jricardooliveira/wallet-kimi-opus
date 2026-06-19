<?php

namespace App\Http\Controllers;

use App\Wallet;
use App\WalletTransaction;
use App\Http\Requests\DebitRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Ramsey\Uuid\Uuid;

class WalletController extends Controller
{
    /**
     * Debit the given wallet.
     *
     * @param  string  $walletId
     * @param  \App\Http\Requests\DebitRequest  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function debit($walletId, DebitRequest $request)
    {
        $idempotencyKey = $request->header('Idempotency-Key');
        $amount = $request->input('amount');
        $currency = $request->input('currency');
        $reference = $request->input('reference');

        try {
            $result = DB::transaction(function () use ($walletId, $idempotencyKey, $amount, $currency, $reference) {
                $wallet = Wallet::lockForUpdate()->find($walletId);

                if (!$wallet) {
                    return [
                        'status' => 404,
                        'payload' => ['error' => 'wallet_not_found'],
                    ];
                }

                $existing = WalletTransaction::where('idempotency_key', $idempotencyKey)
                    ->where('wallet_id', $walletId)
                    ->first();

                if ($existing) {
                    if ($this->matchesRequest($existing, $amount, $currency, $reference)) {
                        return [
                            'status' => 200,
                            'payload' => $this->transactionPayload($existing, $amount),
                        ];
                    }

                    return [
                        'status' => 409,
                        'payload' => ['error' => 'idempotency_conflict'],
                    ];
                }

                if ($wallet->currency !== $currency) {
                    return [
                        'status' => 422,
                        'payload' => ['error' => 'currency_mismatch'],
                    ];
                }

                if (bccomp($wallet->balance, $amount, 4) === -1) {
                    return [
                        'status' => 409,
                        'payload' => ['error' => 'insufficient_funds'],
                    ];
                }

                $newBalance = bcsub($wallet->balance, $amount, 4);

                $wallet->balance = $newBalance;
                $wallet->save();

                $transaction = new WalletTransaction([
                    'id' => Uuid::uuid4()->toString(),
                    'wallet_id' => $walletId,
                    'amount' => '-' . $amount,
                    'balance_after' => $newBalance,
                    'idempotency_key' => $idempotencyKey,
                    'reference' => $reference,
                ]);
                $transaction->save();

                return [
                    'status' => 200,
                    'payload' => $this->transactionPayload($transaction, $amount),
                ];
            });
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                $existing = WalletTransaction::where('idempotency_key', $idempotencyKey)
                    ->where('wallet_id', $walletId)
                    ->first();

                if ($existing && $this->matchesRequest($existing, $amount, $currency, $reference)) {
                    return new JsonResponse($this->transactionPayload($existing, $amount), 200);
                }

                return new JsonResponse(['error' => 'idempotency_conflict'], 409);
            }

            throw $e;
        }

        return new JsonResponse($result['payload'], $result['status']);
    }

    /**
     * Determine whether the existing transaction matches the current request.
     *
     * @param  \App\WalletTransaction  $transaction
     * @param  string  $amount
     * @param  string  $currency
     * @param  string  $reference
     * @return bool
     */
    private function matchesRequest(WalletTransaction $transaction, $amount, $currency, $reference)
    {
        $transactionAmount = ltrim($transaction->amount, '-');

        return $transactionAmount === $amount
            && $transaction->wallet->currency === $currency
            && $transaction->reference === $reference;
    }

    /**
     * Build the payload for a transaction response.
     *
     * @param  \App\WalletTransaction  $transaction
     * @param  string  $amount
     * @return array
     */
    private function transactionPayload(WalletTransaction $transaction, $amount)
    {
        return [
            'transaction_id' => $transaction->id,
            'wallet_id' => $transaction->wallet_id,
            'amount' => $amount,
            'currency' => $transaction->wallet->currency,
            'balance_after' => bcadd($transaction->balance_after, '0', 4),
            'reference' => $transaction->reference,
        ];
    }

    /**
     * Determine whether the query exception is a unique constraint violation.
     *
     * @param  \Illuminate\Database\QueryException  $exception
     * @return bool
     */
    private function isUniqueViolation(QueryException $exception)
    {
        return in_array($exception->getCode(), ['23000', '23505'], true);
    }
}
