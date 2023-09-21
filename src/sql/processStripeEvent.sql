delimiter //

CREATE OR REPLACE PROCEDURE `processStripeEvent`( IN  request JSON )
BEGIN 
    SET @type = JSON_Value( request, "$.type" );

    IF @type = 'checkout.session.completed' THEN
        SET @id = JSON_Value( request, "$.data.object.id" );
        SET @payment_intent = JSON_Value( request, "$.data.object.payment_intent" );
        SET @status = JSON_Value( request, "$.data.object.status" );

        IF @status = 'complete' THEN
            SET @amount = JSON_Value( request, "$.data.object.amount_total" );
            SET @rn = (select id from `blg_hdr_rechnung` where `stripe_id` = @id);
            SET @new_id = (select ifnull(max(id),0)+1 from blg_pay_rechnung);
            INSERT INTO `blg_pay_rechnung` (
                id,
                datum,
                belegnummer,
                art,
                betrag,
                stripe_payment_intent
            ) values (
                @new_id,
                curdate(),
                @rn,
                'stripe',
                @amount / 100,
                @payment_intent
            );
        END IF;
        
    END IF;

END //


CREATE TRIGGER IF NOT EXISTS stripe_webhook_processStripeEvent
AFTER INSERT  
ON stripe_webhook FOR EACH ROW
BEGIN
    call processStripeEvent(new.eventdata);
END //



CREATE TRIGGER IF NOT EXISTS blg_hdr_rechnung_project_state_after_pay
AFTER UPDATE  
ON blg_hdr_rechnung FOR EACH ROW
BEGIN
    if new.offen = 0 then
        update projectmanagement set 
            state = '103' 
        where 
            invoice_id = new.id
            and name = new.referenz
            and state = '102'
        ;
    end if;
END //