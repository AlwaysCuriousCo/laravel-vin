<?php

namespace AlwaysCurious\Vin\Tests;

use AlwaysCurious\Vin\Facades\Vin;
use AlwaysCurious\Vin\VinValidationError;
use Illuminate\Support\Facades\Http;

class VinValidationTest extends TestCase
{
    /** Structurally valid AND carries a correct 9th-position check digit. */
    private const VALID = '7YAMYFS50TY009706';

    /** Same VIN with the 9th position flipped: still structural, wrong check digit. */
    private const BAD_CHECK_DIGIT = '7YAMYFS51TY009706';

    /**
     * @spec VIN-025
     */
    public function test_a_valid_vin_passes_every_check(): void
    {
        // Also proves normalization: lowercase + surrounding whitespace still passes.
        $result = Vin::inspect('  7yamyfs50ty009706 ');

        $this->assertTrue($result->passes());
        $this->assertTrue($result->valid);
        $this->assertTrue($result->structurallyValid);
        $this->assertTrue($result->checkDigitValid);
        $this->assertSame([], $result->errors);
        $this->assertSame(self::VALID, $result->vin);
    }

    /**
     * @spec VIN-025
     */
    public function test_a_bad_check_digit_fails_with_a_reason(): void
    {
        $result = Vin::inspect(self::BAD_CHECK_DIGIT);

        $this->assertTrue($result->fails());
        // Structure is fine — only the check digit is wrong, and that is what it reports.
        $this->assertTrue($result->structurallyValid);
        $this->assertFalse($result->checkDigitValid);
        $this->assertSame([VinValidationError::InvalidCheckDigit], $result->errors);
        $this->assertTrue($result->hasError(VinValidationError::InvalidCheckDigit));
    }

    /**
     * @spec VIN-025
     */
    public function test_an_illegal_character_is_rejected(): void
    {
        // 17 characters, but the leading 'I' is never allowed in a VIN.
        $result = Vin::inspect('IYAMYFS50TY009706');

        $this->assertTrue($result->fails());
        $this->assertFalse($result->structurallyValid);
        $this->assertSame([VinValidationError::IllegalCharacters], $result->errors);
    }

    /**
     * @spec VIN-025
     */
    public function test_a_wrong_length_is_rejected(): void
    {
        // 16 characters — one short — but every character is otherwise legal.
        $result = Vin::inspect('7YAMYFS50TY00970');

        $this->assertTrue($result->fails());
        $this->assertFalse($result->structurallyValid);
        $this->assertSame([VinValidationError::WrongLength], $result->errors);
    }

    /**
     * @spec VIN-025
     */
    public function test_it_reports_length_and_charset_failures_together(): void
    {
        // Too short AND contains an illegal 'O' — both reasons are surfaced.
        $result = Vin::inspect('O123');

        $this->assertEqualsCanonicalizing(
            [VinValidationError::WrongLength, VinValidationError::IllegalCharacters],
            $result->errors,
        );
        $this->assertNotEmpty($result->messages());
    }

    /**
     * @spec VIN-025
     */
    public function test_inspect_makes_no_network_call(): void
    {
        Http::fake();

        Vin::inspect(self::VALID);
        Vin::inspect(self::BAD_CHECK_DIGIT);
        Vin::inspect('NOT-A-VIN');

        Http::assertNothingSent();
    }

    /**
     * @spec VIN-025
     */
    public function test_inspect_agrees_with_is_valid_on_structure(): void
    {
        // structurallyValid mirrors the lenient isValid() gate exactly — a bad check digit is
        // structurally valid, an illegal character is not.
        foreach ([self::VALID, self::BAD_CHECK_DIGIT, 'IYAMYFS50TY009706', '7YAMYFS50TY00970', ''] as $vin) {
            $this->assertSame(
                Vin::isValid($vin),
                Vin::inspect($vin)->structurallyValid,
                "structurallyValid disagreed with isValid() for [{$vin}]",
            );
        }
    }

    /**
     * @spec VIN-025
     */
    public function test_it_serializes_to_a_json_friendly_array(): void
    {
        $result = Vin::inspect(self::BAD_CHECK_DIGIT);

        $this->assertSame([
            'vin' => self::BAD_CHECK_DIGIT,
            'valid' => false,
            'structurally_valid' => true,
            'check_digit_valid' => false,
            'errors' => ['invalid_check_digit'],
        ], $result->toArray());
    }
}
