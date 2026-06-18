<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $holdingIdByIsin = DB::table('stock_holdings')
            ->whereNotNull('isin')
            ->get(['id', 'isin'])
            ->mapWithKeys(fn (object $holding): array => [strtoupper((string) $holding->isin) => $holding->id]);

        DB::table('depot_transactions')
            ->where('type', 'deposit')
            ->whereNotNull('note')
            ->where('note', 'like', '%DE%')
            ->orderBy('id')
            ->chunkById(100, function ($transactions) use ($holdingIdByIsin): void {
                foreach ($transactions as $transaction) {
                    $note = (string) $transaction->note;

                    if (! $this->isDistributionNote($note)) {
                        continue;
                    }

                    $isin = $this->isinFromNote($note);

                    DB::table('depot_transactions')
                        ->where('id', $transaction->id)
                        ->update([
                            'type' => 'dividend',
                            'stock_holding_id' => $isin === null ? null : $holdingIdByIsin->get($isin),
                            'is_external_cashflow' => false,
                            'affects_performance' => true,
                        ]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('depot_transactions')
            ->where('type', 'dividend')
            ->whereNotNull('note')
            ->where('note', 'like', '%DE%')
            ->orderBy('id')
            ->chunkById(100, function ($transactions): void {
                foreach ($transactions as $transaction) {
                    $note = (string) $transaction->note;

                    if (! $this->isDistributionNote($note)) {
                        continue;
                    }

                    DB::table('depot_transactions')
                        ->where('id', $transaction->id)
                        ->update([
                            'type' => 'deposit',
                            'stock_holding_id' => null,
                            'is_external_cashflow' => true,
                            'affects_performance' => false,
                        ]);
                }
            });
    }

    private function isDistributionNote(string $note): bool
    {
        $normalizedNote = strtolower($note);

        return str_contains($note, 'Ertr')
            || str_contains($note, 'Aussch')
            || str_contains($normalizedNote, 'dividend');
    }

    private function isinFromNote(string $note): ?string
    {
        preg_match('/\b[A-Z]{2}[A-Z0-9]{10}\b/', strtoupper($note), $matches);

        return $matches[0] ?? null;
    }
};
