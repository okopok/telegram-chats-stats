<?php

namespace ChatStats\Stats;

use ChatStats\Messages\MessageCollection;

/**
 * Ссылки и сайты: какие сайты (веб-превью) публикуются в чате,
 * по всему чату и по участникам.
 */
class TopSitesHandler extends AbstractStatHandler
{
    public function key(): string
    {
        return 'topSites';
    }

    public function description(): string
    {
        return 'Ссылки и сайты';
    }

    public function handle(MessageCollection $messages): array
    {
        $siteTotals = [];
        $byUser = [];
        foreach ($messages->sitesByUser() as $user => $sites) {
            $userSites = [];
            foreach ($sites as $site => $count) {
                $siteTotals[$site] = ($siteTotals[$site] ?? 0) + $count;
                $userSites[] = ['site' => $site, 'count' => $count];
            }
            usort($userSites, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
            $byUser[] = ['user' => $user, 'sites' => $userSites];
        }
        arsort($siteTotals);
        $totals = [];
        foreach (array_slice($siteTotals, 0, 15, true) as $site => $count) {
            $totals[] = ['site' => $site, 'count' => $count];
        }
        usort($byUser, static function (array $a, array $b): int {
            $sumA = array_sum(array_column($a['sites'], 'count'));
            $sumB = array_sum(array_column($b['sites'], 'count'));
            return $sumB <=> $sumA;
        });

        return ['totals' => $totals, 'by_user' => $byUser];
    }
}