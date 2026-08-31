<?php

declare(strict_types=1);

namespace Taxora\Sdk\Enums;

/**
 * How hard a Smart Enrichment lookup is allowed to search.
 *
 * DEFAULT  Searches with one AI provider and consults a second one only when the first finds
 *          nothing. One paid search per lookup in the common case.
 *
 * COMPLEX  Two independent AI providers search from the start and every VAT number either of them
 *          proposes is put through the tax authority's checks until one confirms. Noticeably better
 *          on hard lookups — companies whose VAT only appears in a website Impressum — and it costs
 *          more per lookup, so it is opt-in per request rather than the default.
 */
enum SmartEnrichmentMode: string
{
    case DEFAULT = 'default';
    case COMPLEX = 'complex';
}
