<?php

namespace App\Services;

use App\Models\MetaAdAccount;
use App\Models\MetaCampaignMetric;
use FacebookAds\Api;
use FacebookAds\Object\AdAccount;
use FacebookAds\Object\Fields\AdsInsightsFields;
use Illuminate\Support\Facades\Log;

class MetaAdsService
{
    public function syncAccount(MetaAdAccount $account, string $datePreset = 'last_30d'): array
    {
        if ($account->isTokenExpired()) {
            Log::warning("Meta token expired for account: {$account->ad_account_id}");
            return ['success' => false, 'message' => 'Token expired'];
        }

        try {
            Api::init(
                config('services.meta.app_id'),
                config('services.meta.app_secret'),
                $account->access_token
            );

            $adAccount = new AdAccount($account->ad_account_id);

            $insights = $adAccount->getInsights(
                [
                    AdsInsightsFields::CAMPAIGN_ID,
                    AdsInsightsFields::CAMPAIGN_NAME,
                    AdsInsightsFields::SPEND,
                    AdsInsightsFields::IMPRESSIONS,
                    AdsInsightsFields::CLICKS,
                    AdsInsightsFields::REACH,
                    AdsInsightsFields::CTR,
                    AdsInsightsFields::CPC,
                    AdsInsightsFields::CPM,
                    AdsInsightsFields::DATE_START,
                    AdsInsightsFields::DATE_STOP,
                    'purchase_roas',
                    'actions',
                ],
                [
                    'level'          => 'campaign',
                    'date_preset'    => $datePreset,
                    'time_increment' => 1,
                ]
            );

            $synced = 0;

            foreach ($insights as $insight) {
                $data = $insight->getData();

                $conversions = 0;
                if (isset($data['actions'])) {
                    foreach ($data['actions'] as $action) {
                        if ($action['action_type'] === 'purchase') {
                            $conversions = (int) $action['value'];
                        }
                    }
                }

                $roas = 0;
                if (isset($data['purchase_roas'][0]['value'])) {
                    $roas = (float) $data['purchase_roas'][0]['value'];
                }

                MetaCampaignMetric::updateOrCreate(
                    [
                        'meta_ad_account_id' => $account->id,
                        'campaign_id'        => $data['campaign_id'],
                        'date'               => $data['date_start'],
                    ],
                    [
                        'campaign_name' => $data['campaign_name'],
                        'spend'         => (float) ($data['spend'] ?? 0),
                        'impressions'   => (int) ($data['impressions'] ?? 0),
                        'clicks'        => (int) ($data['clicks'] ?? 0),
                        'reach'         => (int) ($data['reach'] ?? 0),
                        'conversions'   => $conversions,
                        'ctr'           => (float) ($data['ctr'] ?? 0),
                        'cpc'           => (float) ($data['cpc'] ?? 0),
                        'cpm'           => (float) ($data['cpm'] ?? 0),
                        'roas'          => $roas,
                    ]
                );

                $synced++;
            }

            $account->update(['last_synced_at' => now()]);

            return ['success' => true, 'synced' => $synced];

        } catch (\Exception $e) {
            Log::error("Meta Ads sync error [{$account->ad_account_id}]: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}