<?php

/**
 * Runnable without WordPress: cleans a snippet of post HTML, redacts it and
 * estimates its tokens with the same classes the plugin uses.
 *
 *   php examples/redact-standalone.php
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/autoload.php';

use IndianOpinion\FinetuneExport\Cleaner;
use IndianOpinion\FinetuneExport\CleanOptions;
use IndianOpinion\FinetuneExport\Estimate\HeuristicEstimator;
use IndianOpinion\FinetuneExport\Profile;
use IndianOpinion\FinetuneExport\Redaction\AddressRule;
use IndianOpinion\FinetuneExport\Redaction\EmailRule;
use IndianOpinion\FinetuneExport\Redaction\IpRule;
use IndianOpinion\FinetuneExport\Redaction\NameRule;
use IndianOpinion\FinetuneExport\Redaction\PhoneRule;
use IndianOpinion\FinetuneExport\Redaction\RedactionContext;
use IndianOpinion\FinetuneExport\Redaction\Redactor;
use IndianOpinion\FinetuneExport\Redaction\UrlRule;

$html = <<<'HTML'
<!-- wp:paragraph --><p>Dr Meera Iyer wrote from meera(at)example.org on 12 March 2026.
Call her office on +91 98765 43210 or visit 14 Lajpat Nagar, New Delhi 110024.</p><!-- /wp:paragraph -->
<p>The council met Asha Rao. [share_buttons] <script>track()</script>
Read more at https://example.org/story?utm_source=mail.</p>
HTML;

$text = (new Cleaner())->clean($html, 'text', new CleanOptions());
$redactor = new Redactor([
    new UrlRule(),
    new EmailRule(),
    new IpRule(),
    new PhoneRule(),
    new AddressRule(),
    new NameRule(['Asha Rao']),
]);
$result = $redactor->redact($text, new RedactionContext(Profile::DEFAULT_PLACEHOLDERS));
$record = ['text' => $result->text];

echo json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
echo 'Replacements: ', json_encode($result->counts), "\n";
echo 'Estimated tokens (an estimate): ', (new HeuristicEstimator())->estimate($record, 'text'), "\n";
