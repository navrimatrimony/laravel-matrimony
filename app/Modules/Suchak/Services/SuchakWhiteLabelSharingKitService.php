<?php

namespace App\Modules\Suchak\Services;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakCustomerPaymentDocument;
use App\Models\SuchakProfileRepresentation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class SuchakWhiteLabelSharingKitService
{
    public const POWERED_BY_FOOTER = 'Powered by Navri Mile Navryala';

    public function __construct(
        private readonly SuchakAccessService $accessService,
        private readonly SuchakCandidateMaskingService $maskingService,
        private readonly SuchakCustomerPaymentService $customerPaymentService,
        private readonly SuchakQrCodeImageService $qrCodeImageService,
    ) {
    }

    /**
     * @return array{suchak_account_id: int, powered_by_footer: string, is_publicly_routable: bool, public_url: ?string, assets: array<int, array<string, mixed>>}
     */
    public function assetsFor(SuchakAccount $account): array
    {
        $account->refresh()->loadMissing(['cityLocation', 'talukaLocation', 'districtLocation', 'stateLocation']);

        if (! $this->accessService->canOperate($account)) {
            return $this->emptyKit($account);
        }

        $publicUrl = $this->accessService->canPubliclyRoute($account)
            ? route('suchak.marketplace.show', $account, true)
            : null;
        $publicQr = $publicUrl === null ? null : $this->qrCodeImageService->svgDataUri($publicUrl, 220);

        $assets = [];
        if ($publicUrl !== null && $publicQr !== null) {
            $representation = $this->shareableRepresentation($account);
            if ($representation !== null) {
                $assets[] = $this->whatsappProfileCard($account, $representation, $publicUrl, $publicQr);
            }

            $assets[] = $this->qrPoster($account, $publicUrl, $publicQr);
            $assets[] = $this->officePoster($account, $publicUrl, $publicQr);
            $assets[] = $this->visitingCardQr($account, $publicUrl, $publicQr);
        }

        $receipt = $this->latestReceiptDocument($account);
        if ($receipt !== null) {
            $assets[] = $this->receiptVerificationQr($receipt);
        }

        return [
            'suchak_account_id' => (int) $account->id,
            'powered_by_footer' => self::POWERED_BY_FOOTER,
            'is_publicly_routable' => $publicUrl !== null,
            'public_url' => $publicUrl,
            'assets' => $assets,
        ];
    }

    /**
     * @return array{suchak_account_id: int, powered_by_footer: string, is_publicly_routable: bool, public_url: null, assets: array<int, array<string, mixed>>}
     */
    private function emptyKit(SuchakAccount $account): array
    {
        return [
            'suchak_account_id' => (int) $account->id,
            'powered_by_footer' => self::POWERED_BY_FOOTER,
            'is_publicly_routable' => false,
            'public_url' => null,
            'assets' => [],
        ];
    }

    private function shareableRepresentation(SuchakAccount $account): ?SuchakProfileRepresentation
    {
        /** @var SuchakProfileRepresentation|null $representation */
        $representation = SuchakProfileRepresentation::query()
            ->with([
                'matrimonyProfile.gender',
                'matrimonyProfile.maritalStatus',
                'matrimonyProfile.religion',
                'matrimonyProfile.caste',
                'matrimonyProfile.location.parent.parent.parent',
                'matrimonyProfile.occupationMaster',
            ])
            ->where('suchak_account_id', $account->id)
            ->withValidConsent()
            ->whereHas('matrimonyProfile', fn (Builder $query) => $query
                ->where('lifecycle_state', 'active')
                ->where('is_suspended', false))
            ->orderByDesc('first_verified_consent_at')
            ->orderByDesc('id')
            ->first();

        return $representation;
    }

    private function latestReceiptDocument(SuchakAccount $account): ?SuchakCustomerPaymentDocument
    {
        /** @var SuchakCustomerPaymentDocument|null $receipt */
        $receipt = SuchakCustomerPaymentDocument::query()
            ->where('suchak_account_id', $account->id)
            ->where('document_type', SuchakCustomerPaymentDocument::TYPE_RECEIPT)
            ->whereNotNull('verification_code')
            ->with([
                'customerPayment.suchakAccount',
                'customerPayment.customerContext',
                'customerPayment.servicePackage',
                'customerPayment.customerAgreement',
            ])
            ->latest('issued_at')
            ->latest('id')
            ->first();

        return $receipt;
    }

    /**
     * @return array<string, mixed>
     */
    private function whatsappProfileCard(
        SuchakAccount $account,
        SuchakProfileRepresentation $representation,
        string $publicUrl,
        string $publicQr,
    ): array {
        $profile = $representation->matrimonyProfile;
        $summary = $profile instanceof MatrimonyProfile
            ? $this->maskingService->maskedSummary($profile, $representation)
            : [];
        $lines = $this->profileCardLines($summary);

        return $this->asset(
            $account,
            'whatsapp_profile_card',
            'WhatsApp profile card',
            'suchak_profile_representation',
            (int) $representation->id,
            $publicUrl,
            $publicQr,
            $this->displayName($account),
            $lines,
            $this->shareText($this->displayName($account), $lines, $publicUrl),
        );
    }

    /**
     * The masked candidate lines on the WhatsApp profile card — reused by both
     * the web sharing kit and the mobile per-candidate share so the message
     * format never diverges.
     *
     * @param  array<string, mixed>  $summary
     * @return array<int, string>
     */
    private function profileCardLines(array $summary): array
    {
        $candidateReference = (string) ($summary['candidate_reference'] ?? 'masked-candidate');

        return [
            'Candidate: '.$candidateReference,
            'Age: '.($summary['basic']['age_range'] ?? 'Not available'),
            'Community: '.$this->joinSafe([$summary['community']['religion'] ?? null, $summary['community']['caste'] ?? null], ' / '),
            'Location: '.$this->joinSafe([$summary['location']['city'] ?? null, $summary['location']['district'] ?? null], ', '),
        ];
    }

    /**
     * One candidate's masked share card (photo + text) for the Suchak app to
     * send a customer over WhatsApp. Same masking + line format as the web
     * whatsappProfileCard; adds the shareable photo URL (null when the candidate
     * hides their photo, so the app falls back to a text-only share).
     *
     * @return array{title: string, lines: array<int, string>, photo_url: ?string, has_photo: bool, share_text: string, public_url: ?string}
     */
    public function profileShareCard(SuchakAccount $account, SuchakProfileRepresentation $representation): array
    {
        $account->loadMissing(['cityLocation', 'talukaLocation', 'districtLocation', 'stateLocation']);

        $profile = $representation->matrimonyProfile;
        $summary = $profile instanceof MatrimonyProfile
            ? $this->maskingService->maskedSummary($profile, $representation)
            : [];

        $title = $this->displayName($account);
        $lines = $this->profileCardLines($summary);
        $publicUrl = $this->accessService->canPubliclyRoute($account)
            ? route('suchak.marketplace.show', $account, true)
            : null;
        $photoUrl = $summary['photo']['url'] ?? null;
        $photoUrl = is_string($photoUrl) && $photoUrl !== '' ? $photoUrl : null;

        return [
            'title' => $title,
            'lines' => $lines,
            'photo_url' => $photoUrl,
            'has_photo' => $photoUrl !== null,
            'share_text' => $publicUrl !== null
                ? $this->shareText($title, $lines, $publicUrl)
                : collect([$title])->merge($lines)->push(self::POWERED_BY_FOOTER)->implode("\n"),
            'public_url' => $publicUrl,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function qrPoster(SuchakAccount $account, string $publicUrl, string $publicQr): array
    {
        $lines = [
            'Verified Suchak profile',
            'Area: '.$this->areaLabel($account),
            'Scan to view platform-verified Suchak details',
        ];

        return $this->asset(
            $account,
            'qr_poster',
            'QR poster',
            'suchak_account',
            (int) $account->id,
            $publicUrl,
            $publicQr,
            $this->displayName($account),
            $lines,
            $this->shareText($this->displayName($account), $lines, $publicUrl),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function officePoster(SuchakAccount $account, string $publicUrl, string $publicQr): array
    {
        $lines = [
            'Office: '.$this->displayName($account),
            'Area: '.$this->areaLabel($account),
            'Profile and service information available through platform link',
        ];

        return $this->asset(
            $account,
            'office_poster',
            'Office poster',
            'suchak_account',
            (int) $account->id,
            $publicUrl,
            $publicQr,
            'Verified Suchak Office',
            $lines,
            $this->shareText('Verified Suchak Office', $lines, $publicUrl),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function visitingCardQr(SuchakAccount $account, string $publicUrl, string $publicQr): array
    {
        $lines = [
            $this->displayName($account),
            'Scan for platform profile',
        ];

        return $this->asset(
            $account,
            'visiting_card_qr',
            'Visiting card QR',
            'suchak_account',
            (int) $account->id,
            $publicUrl,
            $publicQr,
            'Visiting card QR',
            $lines,
            $this->shareText('Visiting card QR', $lines, $publicUrl),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function receiptVerificationQr(SuchakCustomerPaymentDocument $receipt): array
    {
        $payment = $receipt->customerPayment;
        $verificationUrl = $this->customerPaymentService->receiptVerificationUrl((string) $receipt->verification_code);
        $lines = [
            'Receipt: '.$receipt->document_number,
            'Status: '.Str::headline((string) $payment->payment_status),
            'Amount: '.$payment->currency.' '.$payment->amount_received,
            'Issued: '.$receipt->issued_at?->format('Y-m-d H:i'),
        ];

        return [
            'type' => 'receipt_verification_qr',
            'label' => 'Receipt verification QR',
            'suchak_account_id' => (int) $receipt->suchak_account_id,
            'source_type' => 'suchak_customer_payment_document',
            'source_id' => (int) $receipt->id,
            'title' => 'Verified receipt',
            'lines' => $lines,
            'qr_url' => $verificationUrl,
            'qr_data_uri' => $this->qrCodeImageService->svgDataUri($verificationUrl, 220),
            'share_text' => $this->shareText('Verified receipt', $lines, $verificationUrl),
            'powered_by_footer' => self::POWERED_BY_FOOTER,
        ];
    }

    /**
     * @param  array<int, string>  $lines
     * @return array<string, mixed>
     */
    private function asset(
        SuchakAccount $account,
        string $type,
        string $label,
        string $sourceType,
        int $sourceId,
        string $qrUrl,
        string $qrDataUri,
        string $title,
        array $lines,
        string $shareText,
    ): array {
        return [
            'type' => $type,
            'label' => $label,
            'suchak_account_id' => (int) $account->id,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'title' => $title,
            'lines' => $lines,
            'qr_url' => $qrUrl,
            'qr_data_uri' => $qrDataUri,
            'share_text' => $shareText,
            'powered_by_footer' => self::POWERED_BY_FOOTER,
        ];
    }

    /**
     * @param  array<int, string|null>  $parts
     */
    private function joinSafe(array $parts, string $glue): string
    {
        $value = collect($parts)->filter(fn ($part) => filled($part))->implode($glue);

        return $value !== '' ? $value : 'Not available';
    }

    private function displayName(SuchakAccount $account): string
    {
        return Str::limit(trim((string) ($account->office_name ?: $account->suchak_name ?: 'Verified Suchak')), 90, '');
    }

    private function areaLabel(SuchakAccount $account): string
    {
        return $this->joinSafe([
            $account->cityLocation?->localizedName(),
            $account->districtLocation?->localizedName(),
        ], ', ');
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function shareText(string $title, array $lines, string $url): string
    {
        return collect([$title])
            ->merge($lines)
            ->push(self::POWERED_BY_FOOTER)
            ->push($url)
            ->implode("\n");
    }
}
