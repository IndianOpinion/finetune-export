<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Redaction;

/**
 * Case 41: street addresses become the address placeholder; UK postcodes,
 * six-digit postal index numbers introduced by a keyword, and US ZIP codes
 * after a state code become the postcode placeholder. Bare five or six digit
 * numbers are not touched.
 */
final class AddressRule implements RedactionRule
{
    public const STREET_TYPES = [
        'Street', 'St', 'Road', 'Rd', 'Lane', 'Ln', 'Avenue', 'Ave', 'Marg', 'Nagar', 'Colony',
        'Drive', 'Close', 'Way', 'Place', 'Pl', 'Crescent', 'Terrace', 'Court', 'Ct', 'Boulevard',
        'Blvd', 'Highway', 'Square', 'Sq', 'Gardens', 'Row', 'Mews', 'Grove', 'Chowk', 'Gali',
        'Peth', 'Enclave', 'Vihar', 'Layout', 'Bagh', 'Salai', 'Path', 'Parkway', 'Alley',
    ];

    public const US_STATES = [
        'AL', 'AK', 'AZ', 'AR', 'CA', 'CO', 'CT', 'DE', 'DC', 'FL', 'GA', 'HI', 'ID', 'IL', 'IN',
        'IA', 'KS', 'KY', 'LA', 'ME', 'MD', 'MA', 'MI', 'MN', 'MS', 'MO', 'MT', 'NE', 'NV', 'NH',
        'NJ', 'NM', 'NY', 'NC', 'ND', 'OH', 'OK', 'OR', 'PA', 'RI', 'SC', 'SD', 'TN', 'TX', 'UT',
        'VT', 'VA', 'WA', 'WV', 'WI', 'WY', 'PR',
    ];

    private const WORD = '[\p{Lu}\d][\p{L}\p{M}\'’.\-]*';
    private const UK = '(?:[A-Z]{1,2}\d[A-Z\d]?|GIR) ?\d[A-Z]{2}';

    /** @var list<string> */
    private array $streetTypes;

    /**
     * @param list<string>|null $streetTypes
     */
    public function __construct(?array $streetTypes = null)
    {
        $this->streetTypes = array_values(array_filter(
            $streetTypes ?? self::STREET_TYPES,
            static fn (string $t): bool => preg_match('/^[\p{L}\p{M}.\-]{1,40}$/u', $t) === 1
        ));
    }

    public function type(): string
    {
        return 'address';
    }

    public function apply(string $text, RedactionContext $context): RedactionResult
    {
        if ($this->streetTypes !== []) {
            $types = implode('|', array_map(static fn (string $t): string => preg_quote($t, '/'), $this->streetTypes));
            $states = implode('|', self::US_STATES);
            $locality = '(?:,\s*' . self::WORD . '(?:[ \t]+' . self::WORD . '){0,2}){0,2}';
            $postcode = '(?:,?\s*(?:' . self::UK . '|\d{3} ?\d{3}|(?:' . $states . ')\s+\d{5}(?:-\d{4})?))?';
            $pattern = '/(?<![\p{L}\p{N}])\d{1,5}[A-Za-z]?(?:[\/\-]\d{1,5}[A-Za-z]?)?,?[ \t]+'
                . '(?:' . self::WORD . '[ \t]+){1,5}?(?:' . $types . ')(?:\.(?=[ \t]*[,\p{Ll}\d]))?(?![\p{L}\p{N}])'
                . $locality . $postcode . '(?![\p{L}\p{N}])/u';
            $text = Redactor::replaceCallback(
                $pattern,
                static fn (array $m): string => $context->placeholder('address'),
                $text
            );
        }

        $text = Redactor::replaceCallback(
            '/(?<![\p{L}\p{N}])' . self::UK . '(?![\p{L}\p{N}])/u',
            static fn (array $m): string => $context->placeholder('postcode'),
            $text
        );
        $text = Redactor::replaceCallback(
            '/(?<![\p{L}\p{N}])((?i:pin\s*code|pincode|pin|postal\s*code|post\s*code|postcode|zip\s*code|zip)'
            . '(?:\s*(?i:no\.?|number))?\s*[:\-–]?\s*)(\d{3} ?\d{3})(?!\d)/u',
            static fn (array $m): string => $context->protect($m[1]) . $context->placeholder('postcode'),
            $text
        );
        $text = Redactor::replaceCallback(
            '/(?<![\p{L}\p{N}])(' . implode('|', self::US_STATES) . ')(,?\s+)(\d{5}(?:-\d{4})?)(?![\d\-])/u',
            static fn (array $m): string => $m[1] . $m[2] . $context->placeholder('postcode'),
            $text
        );
        return new RedactionResult($text);
    }
}
