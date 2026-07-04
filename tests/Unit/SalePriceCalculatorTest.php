<?php

namespace Tests\Unit;

use App\Models\RetailEdgeProduct;
use App\Services\Pricing\SalePriceCalculator;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Tests\TestCase;

/**
 * Unit tests for SalePriceCalculator — the single source of truth for the
 * sale-price rule. Pure computation: no DB, no Shopify. now() is frozen so
 * window checks are deterministic regardless of the configured timezone.
 */
class SalePriceCalculatorTest extends TestCase
{
    private SalePriceCalculator $calculator;

    private CarbonInterface $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new SalePriceCalculator;
        $this->now = Carbon::parse('2026-07-02 12:00:00', 'UTC');
        Carbon::setTestNow($this->now);
    }

    private function activeWindow(): array
    {
        return [$this->now->copy()->subDay(), $this->now->copy()->addDay()];
    }

    private function expiredWindow(): array
    {
        return [$this->now->copy()->subDays(10), $this->now->copy()->subDays(5)];
    }

    private function futureWindow(): array
    {
        return [$this->now->copy()->addDays(5), $this->now->copy()->addDays(10)];
    }

    // ── calculate(): the truth table ────────────────────────────────────

    public function test_both_windows_active_takes_the_lower_price(): void
    {
        [$sStart, $sEnd] = $this->activeWindow();
        [$cStart, $cEnd] = $this->activeWindow();

        $sale = $this->calculator->calculate(89.95, 34.50, $sStart, $sEnd, 51.75, $cStart, $cEnd);

        $this->assertSame(34.50, $sale->price);
        $this->assertSame(89.95, $sale->compareAtPrice);
        $this->assertTrue($sale->onSale());
    }

    public function test_min_is_symmetric_catalogue_wins_when_lower_than_special(): void
    {
        // Under the old rule catalogue had hard priority; now it is a true min,
        // so it must also win symmetrically when it IS the lower price.
        [$sStart, $sEnd] = $this->activeWindow();
        [$cStart, $cEnd] = $this->activeWindow();

        $sale = $this->calculator->calculate(89.95, 60.00, $sStart, $sEnd, 40.00, $cStart, $cEnd);

        $this->assertSame(40.00, $sale->price);
        $this->assertSame(89.95, $sale->compareAtPrice);
    }

    public function test_only_special_active_uses_special(): void
    {
        [$sStart, $sEnd] = $this->activeWindow();
        [$cStart, $cEnd] = $this->expiredWindow();

        $sale = $this->calculator->calculate(89.95, 34.50, $sStart, $sEnd, 51.75, $cStart, $cEnd);

        $this->assertSame(34.50, $sale->price);
        $this->assertSame(89.95, $sale->compareAtPrice);
    }

    public function test_only_catalogue_active_uses_catalogue(): void
    {
        [$sStart, $sEnd] = $this->futureWindow();
        [$cStart, $cEnd] = $this->activeWindow();

        $sale = $this->calculator->calculate(89.95, 34.50, $sStart, $sEnd, 51.75, $cStart, $cEnd);

        $this->assertSame(51.75, $sale->price);
        $this->assertSame(89.95, $sale->compareAtPrice);
    }

    public function test_no_active_windows_falls_back_to_retail(): void
    {
        [$sStart, $sEnd] = $this->expiredWindow();
        [$cStart, $cEnd] = $this->futureWindow();

        $sale = $this->calculator->calculate(89.95, 34.50, $sStart, $sEnd, 51.75, $cStart, $cEnd);

        $this->assertSame(89.95, $sale->price);
        $this->assertSame(0.0, $sale->compareAtPrice);
        $this->assertFalse($sale->onSale());
    }

    public function test_active_sale_above_retail_is_ignored(): void
    {
        [$sStart, $sEnd] = $this->activeWindow();

        $sale = $this->calculator->calculate(50.00, 60.00, $sStart, $sEnd, 0, null, null);

        $this->assertSame(50.00, $sale->price);
        $this->assertSame(0.0, $sale->compareAtPrice);
    }

    public function test_active_sale_equal_to_retail_is_ignored(): void
    {
        [$sStart, $sEnd] = $this->activeWindow();

        $sale = $this->calculator->calculate(50.00, 50.00, $sStart, $sEnd, 0, null, null);

        $this->assertSame(50.00, $sale->price);
        $this->assertSame(0.0, $sale->compareAtPrice);
    }

    public function test_higher_active_price_above_retail_still_allows_lower_one(): void
    {
        // Special 95 exceeds retail, but the active catalogue 51.75 undercuts it:
        // min([95, 51.75]) = 51.75 < retail, so the sale still applies.
        [$sStart, $sEnd] = $this->activeWindow();
        [$cStart, $cEnd] = $this->activeWindow();

        $sale = $this->calculator->calculate(89.95, 95.00, $sStart, $sEnd, 51.75, $cStart, $cEnd);

        $this->assertSame(51.75, $sale->price);
        $this->assertSame(89.95, $sale->compareAtPrice);
    }

    public function test_window_missing_start_or_end_is_inactive(): void
    {
        [$sStart, $sEnd] = $this->activeWindow();

        $missingStart = $this->calculator->calculate(89.95, 34.50, null, $sEnd, 0, null, null);
        $missingEnd = $this->calculator->calculate(89.95, 34.50, $sStart, null, 0, null, null);

        $this->assertSame(89.95, $missingStart->price);
        $this->assertSame(89.95, $missingEnd->price);
    }

    public function test_window_boundaries_are_inclusive(): void
    {
        $startsNow = $this->calculator->calculate(
            89.95, 34.50, $this->now->copy(), $this->now->copy()->addDay(), 0, null, null
        );
        $endsNow = $this->calculator->calculate(
            89.95, 34.50, $this->now->copy()->subDay(), $this->now->copy(), 0, null, null
        );

        $this->assertSame(34.50, $startsNow->price);
        $this->assertSame(34.50, $endsNow->price);
    }

    public function test_zero_sale_prices_are_not_candidates(): void
    {
        [$start, $end] = $this->activeWindow();

        $sale = $this->calculator->calculate(89.95, 0, $start, $end, 0, $start, $end);

        $this->assertSame(89.95, $sale->price);
        $this->assertSame(0.0, $sale->compareAtPrice);
    }

    public function test_zero_retail_with_active_special_sells_at_special_without_compare_at(): void
    {
        // Legacy parity: with no retail price there is nothing to strike through.
        [$start, $end] = $this->activeWindow();

        $sale = $this->calculator->calculate(0, 34.50, $start, $end, 0, null, null);

        $this->assertSame(34.50, $sale->price);
        $this->assertSame(0.0, $sale->compareAtPrice);
    }

    // ── fromEWebItem(): raw SOAP payloads ───────────────────────────────

    public function test_eweb_item_matches_the_acceptance_example(): void
    {
        // SKU 001-026-01083: retail 89.95, special 34.50 (valid), catalogue
        // 51.75 (valid on 2 Jul 2026) → both active, min wins.
        $sale = $this->calculator->fromEWebItem((object) [
            'RetailPrice' => 89.95,
            'SpecialPrice' => 34.50,
            'SpecialPriceStart' => '2026-04-13T00:00:00',
            'SpecialPriceEnd' => '2028-12-30T23:59:59',
            'CataloguePrice' => 51.75,
            'CataloguePriceStart' => '2026-07-02T00:00:00',
            'CataloguePriceEnd' => '2026-07-03T23:59:59',
        ]);

        $this->assertSame(34.50, $sale->price);
        $this->assertSame(89.95, $sale->compareAtPrice);
    }

    public function test_eweb_sentinel_dates_mean_no_window(): void
    {
        $sale = $this->calculator->fromEWebItem((object) [
            'RetailPrice' => 89.95,
            'SpecialPrice' => 34.50,
            'SpecialPriceStart' => '0001-01-01T00:00:00',
            'SpecialPriceEnd' => '2028-12-30T23:59:59',
        ]);

        $this->assertSame(89.95, $sale->price);
        $this->assertSame(0.0, $sale->compareAtPrice);
    }

    public function test_eweb_unparseable_dates_do_not_throw_and_mean_no_window(): void
    {
        $sale = $this->calculator->fromEWebItem((object) [
            'RetailPrice' => 89.95,
            'SpecialPrice' => 34.50,
            'SpecialPriceStart' => 'not-a-date',
            'SpecialPriceEnd' => '2028-12-30T23:59:59',
        ]);

        $this->assertSame(89.95, $sale->price);
        $this->assertSame(0.0, $sale->compareAtPrice);
    }

    public function test_eweb_item_with_missing_price_fields_defaults_to_retail(): void
    {
        $sale = $this->calculator->fromEWebItem((object) ['RetailPrice' => 89.95]);

        $this->assertSame(89.95, $sale->price);
        $this->assertSame(0.0, $sale->compareAtPrice);
    }

    public function test_eweb_dates_are_parsed_as_utc(): void
    {
        // Window ends one hour before the frozen UTC instant → inactive,
        // regardless of the app timezone the dates get converted into.
        $sale = $this->calculator->fromEWebItem((object) [
            'RetailPrice' => 89.95,
            'SpecialPrice' => 34.50,
            'SpecialPriceStart' => '2026-07-01T00:00:00',
            'SpecialPriceEnd' => '2026-07-02T11:00:00',
        ]);

        $this->assertSame(89.95, $sale->price);
    }

    // ── fromModel(): retail_edge_products rows ──────────────────────────

    private function product(array $attrs): RetailEdgeProduct
    {
        $p = new RetailEdgeProduct;
        $p->forceFill($attrs);

        return $p;
    }

    public function test_model_with_active_special_is_on_sale(): void
    {
        $tz = config('app.timezone');
        Carbon::setTestNow(Carbon::parse('2026-07-02 12:00:00', $tz));

        $sale = $this->calculator->fromModel($this->product([
            'retail_price1' => '89.95',
            'special_price' => '34.50',
            'special_price_start' => '2026-04-13 00:00:00',
            'special_price_end' => '2028-12-30 23:59:59',
            'catalogue_price' => '51.75',
            'catalogue_price_start' => '2026-07-05 00:00:00',
            'catalogue_price_end' => '2026-07-06 23:59:59',
        ]));

        $this->assertSame(34.50, $sale->price);
        $this->assertSame(89.95, $sale->compareAtPrice);
    }

    public function test_model_with_null_windows_and_prices_uses_retail(): void
    {
        $sale = $this->calculator->fromModel($this->product([
            'retail_price1' => '89.95',
            'special_price' => null,
            'special_price_start' => null,
            'special_price_end' => null,
            'catalogue_price' => null,
            'catalogue_price_start' => null,
            'catalogue_price_end' => null,
        ]));

        $this->assertSame(89.95, $sale->price);
        $this->assertSame(0.0, $sale->compareAtPrice);
        $this->assertFalse($sale->onSale());
    }
}
