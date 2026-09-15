<?php

use Magento\SalesRule\Api\CouponRepositoryInterface;
use Magento\SalesRule\Api\Data\CouponInterface;
use Magento\SalesRule\Api\Data\RuleInterface;
use Magento\SalesRule\Api\RuleRepositoryInterface;
use Magento\TestFramework\Helper\Bootstrap;

$objectManager = Bootstrap::getObjectManager();
$ruleRepository = $objectManager->get(RuleRepositoryInterface::class);
$couponRepository = $objectManager->get(CouponRepositoryInterface::class);

// $10 discount - multi-use - logged-in customers (group 1)

$rule = $objectManager->create(RuleInterface::class);
$rule->setName('$10 discount - multi-use - logged in')
    ->setIsAdvanced(true)
    ->setStopRulesProcessing(false)
    ->setDiscountQty(0)
    ->setCustomerGroupIds([1])
    ->setWebsiteIds([1])
    ->setCouponType(RuleInterface::COUPON_TYPE_SPECIFIC_COUPON)
    ->setSimpleAction(RuleInterface::DISCOUNT_ACTION_FIXED_AMOUNT_FOR_CART)
    ->setDiscountAmount(10)
    ->setIsActive(true)
    ->setUsesPerCoupon(10)
    ->setUsesPerCustomer(10);

$rule = $ruleRepository->save($rule);

$coupon = $objectManager->create(CouponInterface::class);
$coupon->setCode('10_discount_logged_in')
    ->setRuleId($rule->getRuleId());

$couponRepository->save($coupon);

// $10 discount - single use per customer - logged-in customers (group 1)

$rule = $objectManager->create(RuleInterface::class);
$rule->setName('$10 discount - single use per customer - logged in')
    ->setIsAdvanced(true)
    ->setStopRulesProcessing(false)
    ->setDiscountQty(0)
    ->setCustomerGroupIds([1])
    ->setWebsiteIds([1])
    ->setCouponType(RuleInterface::COUPON_TYPE_SPECIFIC_COUPON)
    ->setSimpleAction(RuleInterface::DISCOUNT_ACTION_FIXED_AMOUNT_FOR_CART)
    ->setDiscountAmount(10)
    ->setIsActive(true)
    ->setUsesPerCoupon(10)
    ->setUsesPerCustomer(1);

$rule = $ruleRepository->save($rule);

$coupon = $objectManager->create(CouponInterface::class);
$coupon->setCode('single_use_per_customer_logged_in')
    ->setRuleId($rule->getRuleId());

$couponRepository->save($coupon);
