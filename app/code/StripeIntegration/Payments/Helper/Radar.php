<?php

namespace StripeIntegration\Payments\Helper;

class Radar
{
    private const RISK_LEVEL_NORMAL = 'Normal';
    private const RISK_LEVEL_ELEVATED = 'Elevated';
    private const RISK_LEVEL_HIGHEST = 'Highest';
    public const RISK_LEVEL_NA = 'NA';
    public const RISK_SCORE_COLUMN_NAME = "stripe_radar_risk_score";
    public const RISK_LEVEL_COLUMN_NAME = "stripe_radar_risk_level";
    public const MANUAL_REVIEW_STATUS_CODE = 'stripe_manual_review';
    public const MANUAL_REVIEW_STATE_CODE = 'stripe_manual_review';

    private $assetRepository;
    private $stripeChargeModelFactory;
    private $stripePaymentIntentModelFactory;
    private $tokenHelper;

    public function __construct(
        \Magento\Framework\View\Asset\Repository $assetRepository,
        \StripeIntegration\Payments\Model\Stripe\ChargeFactory $stripeChargeModelFactory,
        \StripeIntegration\Payments\Model\Stripe\PaymentIntentFactory $stripePaymentIntentModelFactory,
        \StripeIntegration\Payments\Helper\Token $tokenHelper
    ) {
        $this->assetRepository = $assetRepository;
        $this->stripeChargeModelFactory = $stripeChargeModelFactory;
        $this->stripePaymentIntentModelFactory = $stripePaymentIntentModelFactory;
        $this->tokenHelper = $tokenHelper;
    }

    /**
     * get not available risk data icon
     */
    public function getNoRiskIcon()
    {
        return $this->assetRepository->getUrl("StripeIntegration_Payments::svg/risk_data_na.svg");
    }

    public function getRiskElementClass($riskScore = null, $riskLevel = self::RISK_LEVEL_NA)
    {
        $returnClass = 'na';
        if ($riskScore === null) {
            return $returnClass;
        }
        if ($riskScore >= 0 && $riskScore < 6 ) {
            $returnClass = 'normal';
        }
        if (($riskScore >= 6 && $riskScore < 66) || ($riskLevel === self::RISK_LEVEL_NORMAL)) {
            $returnClass = 'normal';
        }
        if (($riskScore >= 66 && $riskScore < 76) || ($riskLevel === self::RISK_LEVEL_ELEVATED)) {
            $returnClass = 'elevated';
        }
        if (($riskScore >= 76) || ($riskLevel === self::RISK_LEVEL_HIGHEST)) {
            $returnClass = 'highest';
        }

        return $returnClass;
    }

    public function setOrderRiskData($order)
    {
        $lastTransactionId = $order->getPayment()->getLastTransId();
        $lastTransactionId = $this->tokenHelper->cleanToken($lastTransactionId);

        if (!$this->tokenHelper->isPaymentIntentToken($lastTransactionId)) {
            return;
        }

        $stripePaymentIntentModel = $this->stripePaymentIntentModelFactory->create()->fromPaymentIntentId($lastTransactionId, ['latest_charge']);
        $stripePaymentIntent = $stripePaymentIntentModel->getStripeObject();

        if (!empty($stripePaymentIntent->latest_charge)) {
            if (is_string($stripePaymentIntent->latest_charge)) {
                $stripeChargeModel = $this->stripeChargeModelFactory->create()->fromChargeId($stripePaymentIntent->latest_charge);
            } else {
                $stripeChargeModel = $this->stripeChargeModelFactory->create()->fromObject($stripePaymentIntent->latest_charge);
            }
            $order->setStripeRadarRiskScore($stripeChargeModel->getRiskScore());
            $order->setStripeRadarRiskLevel($stripeChargeModel->getRiskLevel());
        }
    }

    public function resolveManualReviewActionPermission($order, $result)
    {
        if ($order->getState() === Radar::MANUAL_REVIEW_STATE_CODE) {
            return false;
        }

        return $result;
    }
}