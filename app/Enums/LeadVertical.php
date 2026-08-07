<?php

namespace App\Enums;

/**
 * The 16 lead verticals (BACKEND_BRIEF §7.4). Backed by the 'lead_vertical'
 * option list too — this enum is for code-safety in PHP, the option list is
 * what Studio and the UI read from.
 */
enum LeadVertical: string
{
    case BusinessImmigration = 'BusinessImmigration';
    case Refugee = 'Refugee';
    case SpousalSponsorship = 'SpousalSponsorship';
    case ExpressEntry = 'ExpressEntry';
    case Humanitarian = 'Humanitarian';
    case StudyPermit = 'StudyPermit';
    case LMIA = 'LMIA';
    case PNP = 'PNP';
    case USA = 'USA';
    case CanadaVisa = 'CanadaVisa';
    case InCanada = 'InCanada';
    case Investor = 'Investor';
    case Entrepreneur = 'Entrepreneur';
    case BusinessDevelopment = 'BusinessDevelopment';
    case Resume = 'Resume';
    case General = 'General';

    /**
     * First-character-only capitalisation (BACKEND_BRIEF rule 8) — never Title Case.
     */
    public function label(): string
    {
        return match ($this) {
            self::BusinessImmigration => 'Business immigration',
            self::Refugee => 'Refugee',
            self::SpousalSponsorship => 'Spousal sponsorship',
            self::ExpressEntry => 'Express entry',
            self::Humanitarian => 'Humanitarian',
            self::StudyPermit => 'Study permit',
            self::LMIA => 'LMIA',
            self::PNP => 'PNP',
            self::USA => 'USA',
            self::CanadaVisa => 'Canada visa',
            self::InCanada => 'In canada',
            self::Investor => 'Investor',
            self::Entrepreneur => 'Entrepreneur',
            self::BusinessDevelopment => 'Business development',
            self::Resume => 'Resume',
            self::General => 'General',
        };
    }
}
