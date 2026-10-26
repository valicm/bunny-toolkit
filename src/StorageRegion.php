<?php

declare(strict_types=1);

namespace Valicm\BunnyToolkit;

/**
 * Edge Storage API base URLs. A storage zone is only reachable through the
 * endpoint of its primary region.
 *
 * @see https://bunny.net/docs/api-reference/storage
 */
enum StorageRegion: string
{
    case Falkenstein = 'https://storage.bunnycdn.com';
    case London = 'https://uk.storage.bunnycdn.com';
    case Stockholm = 'https://se.storage.bunnycdn.com';
    case Prague = 'https://cz.storage.bunnycdn.com';
    case Madrid = 'https://es.storage.bunnycdn.com';
    case NewYork = 'https://ny.storage.bunnycdn.com';
    case LosAngeles = 'https://la.storage.bunnycdn.com';
    case Seattle = 'https://wa.storage.bunnycdn.com';
    case Miami = 'https://mi.storage.bunnycdn.com';
    case Singapore = 'https://sg.storage.bunnycdn.com';
    case HongKong = 'https://hk.storage.bunnycdn.com';
    case Tokyo = 'https://jp.storage.bunnycdn.com';
    case Sydney = 'https://syd.storage.bunnycdn.com';
    case SaoPaulo = 'https://br.storage.bunnycdn.com';
    case Johannesburg = 'https://jh.storage.bunnycdn.com';
}
