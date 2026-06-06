<?php

namespace App\Services\Parsing;

final class WizardRelationSchema
{
    /** @var list<string> */
    private const PATERNAL_TYPES = [
        'paternal_grandfather',
        'paternal_grandmother',
        'paternal_uncle',
        'wife_paternal_uncle',
        'paternal_aunt',
        'husband_paternal_aunt',
        'Cousin',
    ];

    /** @var list<string> */
    private const MATERNAL_TYPES = [
        'maternal_address_ajol',
        'maternal_grandfather',
        'maternal_grandmother',
        'maternal_uncle',
        'wife_maternal_uncle',
        'maternal_aunt',
        'husband_maternal_aunt',
        'maternal_cousin',
    ];

    /** @var list<string> */
    private const SIBLING_TYPES = [
        'brother',
        'sister',
        'brother_wife',
        'sister_husband',
    ];

    /** @var array<string, string> */
    private const LABEL_TO_TYPE = [
        'वडिलांचे वडील' => 'paternal_grandfather',
        'आजोबा' => 'paternal_grandfather',
        'वडिलांची आई' => 'paternal_grandmother',
        'आजी' => 'paternal_grandmother',
        'चुलते' => 'paternal_uncle',
        'काका' => 'paternal_uncle',
        'चुलती' => 'wife_paternal_uncle',
        'काकू' => 'wife_paternal_uncle',
        'आत्या' => 'paternal_aunt',
        'मुलाची आत्या' => 'paternal_aunt',
        'मुलाची आत्त्या' => 'paternal_aunt',
        'वडिलांची बहीण' => 'paternal_aunt',
        'वडिलांची बहिण' => 'paternal_aunt',
        'आत्याचे यजमान' => 'husband_paternal_aunt',
        'आत्यांचे यजमान' => 'husband_paternal_aunt',
        'आत्या यजमान' => 'husband_paternal_aunt',
        'चुलत भाऊ' => 'Cousin',
        'चुलत बहीण' => 'Cousin',
        'चुलत बहिण' => 'Cousin',
        'आईचे वडील' => 'maternal_grandfather',
        'मातृ आजोबा' => 'maternal_grandfather',
        'आईची आई' => 'maternal_grandmother',
        'मातृ आजी' => 'maternal_grandmother',
        'मुलाचे मामा' => 'maternal_uncle',
        'मुलीचे मामा' => 'maternal_uncle',
        'मामाचे नाव' => 'maternal_uncle',
        'मामा' => 'maternal_uncle',
        'मामी' => 'wife_maternal_uncle',
        'मावशी' => 'maternal_aunt',
        'माऊशी' => 'maternal_aunt',
        'मावशीचे यजमान' => 'husband_maternal_aunt',
        'मावशीचा नवरा' => 'husband_maternal_aunt',
        'मावस भाऊ' => 'maternal_cousin',
        'मावस बहीण' => 'maternal_cousin',
        'मावस बहिण' => 'maternal_cousin',
        'आजोळ' => 'maternal_address_ajol',
        'आजोळचा पत्ता' => 'maternal_address_ajol',
        'भाऊ' => 'brother',
        'मुलाचा भाऊ' => 'brother',
        'मुलाचे भाऊ' => 'brother',
        'बहीण' => 'sister',
        'बहिण' => 'sister',
        'बहिणी' => 'sister',
        'मुलाची बहीण' => 'sister',
        'मुलाची बहिण' => 'sister',
        'वाहिनी' => 'brother_wife',
        'वहिनी' => 'brother_wife',
        'भावजय' => 'brother_wife',
        'जावई' => 'sister_husband',
        'दाजी' => 'sister_husband',
        'भाऊजी' => 'sister_husband',
        'भावजी' => 'sister_husband',
    ];

    /** @var list<string> */
    private const OTHER_RELATIVES_TEXT_LABELS = [
        'नातेसंबंध',
        'नाते संबंध',
        'नातेवाईक',
        'इतर नातेवाईक',
        'इतर पाहुणे',
        'इतर पाहूणे',
        'पाहुणे',
        'माते संबंध',
        'मातेसंबंध',
        'वडील संबंध',
        'सोयरे',
        'संबंध',
        'उत्तर नातेवाईक',
    ];

    public function isPaternalType(string $type): bool
    {
        return in_array(trim($type), self::PATERNAL_TYPES, true);
    }

    public function isMaternalType(string $type): bool
    {
        return in_array(trim($type), self::MATERNAL_TYPES, true);
    }

    public function isSiblingType(string $type): bool
    {
        return in_array(trim($type), self::SIBLING_TYPES, true);
    }

    public function sectionForRelationType(string $type): ?string
    {
        $type = trim($type);
        if ($this->isSiblingType($type)) {
            return 'siblings';
        }
        if ($this->isPaternalType($type)) {
            return 'relatives';
        }
        if ($this->isMaternalType($type)) {
            return 'alliance';
        }

        return null;
    }

    public function canonicalRelationTypeFromLabel(string $label): ?string
    {
        $normalized = $this->normalizeLabel($label);

        foreach (self::LABEL_TO_TYPE as $alias => $type) {
            if ($this->normalizeLabel($alias) === $normalized) {
                return $type;
            }
        }

        return null;
    }

    public function isOtherRelativesTextLabel(string $label): bool
    {
        $normalized = $this->normalizeLabel($label);
        foreach (self::OTHER_RELATIVES_TEXT_LABELS as $alias) {
            if ($this->normalizeLabel($alias) === $normalized) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function allRelationLabels(): array
    {
        return array_values(array_unique(array_merge(array_keys(self::LABEL_TO_TYPE), self::OTHER_RELATIVES_TEXT_LABELS)));
    }

    /**
     * @return list<string>
     */
    public function otherRelativesTextLabels(): array
    {
        return self::OTHER_RELATIVES_TEXT_LABELS;
    }

    private function normalizeLabel(string $label): string
    {
        $label = trim($label);
        $label = preg_replace('/\s+/u', ' ', $label) ?? $label;

        return mb_strtolower($label);
    }
}
