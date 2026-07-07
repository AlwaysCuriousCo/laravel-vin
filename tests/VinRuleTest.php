<?php

namespace AlwaysCurious\Vin\Tests;

use AlwaysCurious\Vin\Facades\Vin;
use AlwaysCurious\Vin\Rules\Vin as VinRule;
use AlwaysCurious\Vin\Support\VinCheckDigit;
use Illuminate\Support\Facades\Validator;

class VinRuleTest extends TestCase
{
    /** Structurally valid AND carries a correct 9th-position check digit. */
    private const VALID = '7YAMYFS50TY009706';

    /** Same VIN with the 9th position flipped: still structural, wrong check digit. */
    private const BAD_CHECK_DIGIT = '7YAMYFS51TY009706';

    /**
     * @spec VIN-019
     */
    public function test_the_rule_passes_a_structurally_valid_vin_after_normalizing(): void
    {
        $validator = Validator::make(
            ['vin' => '  7yamyfs50ty009706 '],
            ['vin' => [new VinRule]],
        );

        $this->assertTrue($validator->passes());
    }

    /**
     * @spec VIN-019
     */
    public function test_the_rule_fails_a_structurally_invalid_vin(): void
    {
        $validator = Validator::make(['vin' => 'NOT-A-VIN'], ['vin' => [new VinRule]]);

        $this->assertTrue($validator->fails());
        $this->assertStringContainsString('valid 17-character VIN', $validator->errors()->first('vin'));
    }

    /**
     * @spec VIN-019
     * @spec VIN-020
     */
    public function test_the_check_digit_is_only_enforced_when_opted_in(): void
    {
        // Structural-only accepts a wrong check digit...
        $this->assertTrue(Validator::make(['vin' => self::BAD_CHECK_DIGIT], ['vin' => [new VinRule]])->passes());

        // ...withCheckDigit() rejects it...
        $this->assertTrue(Validator::make(['vin' => self::BAD_CHECK_DIGIT], ['vin' => [(new VinRule)->withCheckDigit()]])->fails());

        // ...and accepts a correct one.
        $this->assertTrue(Validator::make(['vin' => self::VALID], ['vin' => [(new VinRule)->withCheckDigit()]])->passes());
    }

    /**
     * @spec VIN-020
     */
    public function test_the_check_digit_helper_matches_the_iso_3779_standard(): void
    {
        $this->assertTrue(VinCheckDigit::matches(self::VALID));
        $this->assertTrue(VinCheckDigit::matches('11111111111111111'));
        $this->assertFalse(VinCheckDigit::matches(self::BAD_CHECK_DIGIT));
    }

    /**
     * @spec VIN-020
     */
    public function test_has_valid_check_digit_is_stricter_than_is_valid(): void
    {
        // isValid() stays structural (lenient); the check-digit gate is a separate, stricter opt-in.
        $this->assertTrue(Vin::isValid(self::BAD_CHECK_DIGIT));
        $this->assertFalse(Vin::hasValidCheckDigit(self::BAD_CHECK_DIGIT));
        $this->assertTrue(Vin::hasValidCheckDigit(self::VALID));
    }
}
