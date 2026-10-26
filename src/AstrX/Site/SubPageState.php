<?php

declare(strict_types = 1);

namespace AstrX\Site;

/**
 * The page controller's pagination state, frozen.
 *
 * The page controller (e.g. MainController) registers it, so that
 * DefaultTemplateContext::finalise() can build paging links for both the page
 * and its comments, with the comment segments after the page's. The query-mode
 * key names are included for the hidden inputs that carry the news state
 * through the comment filter form.
 */
final class SubPageState
{
    public function __construct(
        /** Already-translated URL identifier (e.g. 'main', 'news'). */
        public readonly string $resolvedUrlId,
        /** Current page number. */ public readonly int $page,
        /** Current order word as it appears in the URL (may be localised). */
        public readonly string $order,
        /** Current items-per-page. */ public readonly int $perPage,
        /** Default page number (almost always 1). */
        public readonly int $defaultPage,
        /** Default order word for this page type. */
        public readonly string $defaultOrder,
        /** Default items-per-page for this page type. */
        public readonly int $defaultPerPage,
        /** Query-mode key for page number (e.g. 'pn'). */
        public readonly string $pnKey = 'pn',
        /** Query-mode key for order (e.g. 'order'). */
        public readonly string $orderKey = 'order',
        /** Query-mode key for items-per-page (e.g. 'show'). */
        public readonly string $showKey = 'show',
    ) {
    }
}