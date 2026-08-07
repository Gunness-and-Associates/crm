<?php

namespace App\Enums;

/**
 * Backed by the 'lead_stage' option list — this enum is for code-safety in
 * PHP, the option list is what Studio and the UI read from.
 */
enum LeadStage: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case FollowUp = 'follow_up';
    case Qualified = 'qualified';
    case Converted = 'converted';
    case Lost = 'lost';

    /**
     * First-character-only capitalisation (BACKEND_BRIEF rule 8) — never Title Case.
     */
    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Contacted => 'Contacted',
            self::FollowUp => 'Follow up',
            self::Qualified => 'Qualified',
            self::Converted => 'Converted',
            self::Lost => 'Lost',
        };
    }
}
