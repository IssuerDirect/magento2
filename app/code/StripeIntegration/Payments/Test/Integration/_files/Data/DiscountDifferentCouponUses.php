<?php

use Magento\SalesRule\Api\CouponRepositoryInterface;
use Magento\SalesRule\Api\Data\CouponInterface;
use Magento\SalesRule\Api\Data\RuleInterface;
use Magento\SalesRule\Api\RuleRepositoryInterface;
use Magento\TestFramework\Helper\Bootstrap;

$objectManager = Bootstrap::getObjectManager();

$rule = $objectManager->create(RuleInterface::class);
$rule->setName('$10 discount - single use global')
    ->setIsAdvanced(true)
    ->setStopRulesProcessing(false)
    ->setDiscountQty(0)
    ->setCustomerGroupIds([0])
    ->setWebsiteIds([1])
    ->setCouponType(RuleInterface::COUPON_TYPE_SPECIFIC_COUPON)
    ->setSimpleAction(RuleInterface::DISCOUNT_ACTION_FIXED_AMOUNT_FOR_CART)
    ->setDiscountAmount(10)
    ->setIsActive(true)
    ->setUsesPerCoupon(1);

$ruleRepository = $objectManager->get(RuleRepositoryInterface::class);
$rule = $ruleRepository->save($rule);

$coupon = $objectManager->create(CouponInterface::class);
$coupon->setCode('single_use_global')
    ->setRuleId($rule->getRuleId());

$couponRepository = $objectManager->get(CouponRepositoryInterface::class);
$coupon = $couponRepository->save($coupon);

$rule = $objectManager->create(RuleInterface::class);
$rule->setName('$10 discount - multiple use global')
    ->setIsAdvanced(true)
    ->setStopRulesProcessing(false)
    ->setDiscountQty(0)
    ->setCustomerGroupIds([0])
    ->setWebsiteIds([1])
    ->setCouponType(RuleInterface::COUPON_TYPE_SPECIFIC_COUPON)
    ->setSimpleAction(RuleInterface::DISCOUNT_ACTION_FIXED_AMOUNT_FOR_CART)
    ->setDiscountAmount(10)
    ->setIsActive(true)
    ->setUsesPerCoupon(10);

$ruleRepository = $objectManager->get(RuleRepositoryInterface::class);
$rule = $ruleRepository->save($rule);

$coupon = $objectManager->create(CouponInterface::class);
$coupon->setCode('multiple_use_global')
    ->setRuleId($rule->getRuleId());

$couponRepository = $objectManager->get(CouponRepositoryInterface::class);
$coupon = $couponRepository->save($coupon);
