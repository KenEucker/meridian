<?php

declare(strict_types=1);

namespace App\Domain\Dashboard;

/**
 * Who answers a widget (M18.28).
 *
 * Almost everything is the node's: it holds the shifts, the attendance, the
 * incidents, and the authority to decide who may read them, and a client that
 * computed any of it would be keeping a second copy that can only drift.
 *
 * Two kiosk widgets are the device's instead, and it is not a shortcut. Whether
 * the local node is reachable and who is signed in at this workstation are facts
 * about the machine somebody is standing at. A node asked for them could only
 * answer for a request that already arrived, which is the one case where the
 * answer is never in doubt.
 */
enum DashboardWidgetEvaluation: string
{
    case Node = 'node';
    case Device = 'device';
}
