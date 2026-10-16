USE finance_ai;

ALTER TABLE pro_subscriptions
    MODIFY razorpay_order_id VARCHAR(64) NULL;
