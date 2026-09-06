<?php

function seed_sqlite(PDO $pdo): void
{
    $pdo->beginTransaction();
    try {
        foreach ([
            ['Admin', 'Full application and user administration'],
            ['Editor', 'Create and maintain documentation'],
            ['Support Engineer', 'Search, troubleshoot, and provide feedback'],
            ['Viewer', 'Read published documentation'],
        ] as $role) {
            $pdo->prepare('INSERT INTO roles (name, description) VALUES (?, ?)')->execute($role);
        }
        $hash = '$2b$10$axSjcZ2gOEEh5OeQyOgUHOcNnPImgYJpUPLqIRmcxAJkBQxp0Ld1W';
        $pdo->prepare('INSERT INTO users (role_id, name, email, password_hash) VALUES (1, ?, ?, ?)')->execute(['Development Administrator', 'admin@supporthub.local', $hash]);
        $pdo->prepare('INSERT INTO users (role_id, name, email, password_hash) VALUES (3, ?, ?, ?)')->execute(['Sample Support Engineer', 'engineer@supporthub.local', $hash]);

        $systems = [
            ['Shipping Platform', 'SHIP'], ['Warehouse Management System', 'WMS'],
            ['Freight Management System', 'FMS'], ['Order Management System', 'OMS'],
            ['Integration API', 'API'],
        ];
        foreach ($systems as $row) $pdo->prepare('INSERT INTO systems (name, code) VALUES (?, ?)')->execute($row);
        foreach ([[1,'Label Processing'],[1,'Rate Shopping'],[2,'Order Import'],[3,'Load Planning'],[5,'Carrier Gateway']] as $row) $pdo->prepare('INSERT INTO modules (system_id, name) VALUES (?, ?)')->execute($row);
        foreach (['Address Validation','Carrier Validation','Product Mapping','Order Import','Load Visibility','Rate Retrieval','Standard Operating Procedure','Access and Configuration'] as $name) $pdo->prepare('INSERT INTO categories (name) VALUES (?)')->execute([$name]);
        foreach ([['UPS','UPS'],['USPS','USPS'],['DHL Express','DHL'],['UPS Mail Innovations','UPS-MI'],['Internal / None','INTERNAL']] as $row) $pdo->prepare('INSERT INTO carriers (name, code) VALUES (?, ?)')->execute($row);
        foreach (['postal-code','international','military-address','label','rate','import','validation','configuration','escalation'] as $name) $pdo->prepare('INSERT INTO tags (name) VALUES (?)')->execute([$name]);

        $articles = [
            ['Error','UPS Missing/Invalid PostalCode of payment account',1,1,1,1,'UPS-ADDR-104','High','Missing or invalid PostalCode of payment account','UPS rejects the shipment because the payment account address does not contain a valid postal code.','The shipper or third-party billing account profile has an empty, malformed, or country-mismatched postal code.','Open carrier account configuration, verify the billing address country and postal code, save the account, then retry the shipment.','1. Open Settings > Carrier Accounts > UPS.\n2. Select the payment account used by the order.\n3. Confirm country and PostalCode match the UPS account profile.\n4. Save and re-rate the shipment.\n5. Generate the label again.','A successful rate response and label confirm the correction.','Escalate to Carrier Integrations with account alias and correlation ID after two failed retries.','UPS postal code payment account label address','Published'],
            ['SOP','USPS ExtraServices not valid at APO/FPO/DPO/FAS',1,1,2,2,'USPS-APO-220','Medium','ExtraServices are not valid for the destination address','A military mail label fails when an unsupported USPS extra service is selected.','APO, FPO, DPO, and FAS destinations accept a limited set of extra services.','Remove the unsupported option and select a service supported for the destination and mail class.','1. Confirm the city is APO, FPO, DPO, or FAS.\n2. Review selected ExtraServices.\n3. Remove Signature Confirmation or other unsupported service.\n4. Retain only services allowed by the USPS military mail matrix.\n5. Recreate the label.','The USPS response returns a label without an ExtraServices validation error.','Escalate with sanitized request/response payloads if supported services still fail.','USPS APO FPO DPO military extra services','Published'],
            ['Error','DHL Product Finding Failed',1,2,3,3,'DHL-PRODUCT-404','High','Product finding failed','DHL cannot select an eligible product for the shipment route and package.','Origin, destination, weight, dimensions, or account capabilities do not match an enabled DHL product.','Validate route and parcel data, then confirm the account is enabled for the requested DHL product.','1. Confirm origin and destination country codes.\n2. Verify weight and dimensions are positive and within product limits.\n3. Check DHL account product entitlements.\n4. Remove a forced service code and retry product discovery.','At least one eligible DHL service appears in the rate response.','Escalate to DHL support with timestamp, account alias, and request ID.','DHL product finding service route dimensions','Published'],
            ['Error','DHL Data Validation Failed',1,1,2,3,'DHL-VALIDATION-400','Medium','Data validation failed','DHL rejected one or more shipment data fields before rating or label creation.','A required address, customs, contact, or package field is missing or in an invalid format.','Correct the field identified in the DHL validation detail and resubmit.','1. Open the carrier response detail.\n2. Identify the field path in the validation message.\n3. Correct the source order or shipment.\n4. Confirm customs data for international shipments.\n5. Retry once.','The request passes validation and returns rates or a label.','Send the redacted payload and correlation ID to Carrier Integrations if no field is identified.','DHL validation customs address API','Published'],
            ['Error','WMS Order failed to import',2,3,4,5,'WMS-IMPORT-001','Critical','Order failed to import','An order is present upstream but is not available for warehouse allocation.','Required customer, SKU, facility, or address mapping is missing, or the import message is malformed.','Correct the source data or mapping and replay the message from the integration queue.','1. Locate the order in Integration Monitor.\n2. Review the first blocking validation message.\n3. Correct master data or the source order.\n4. Replay the message once.\n5. Confirm the order reaches Ready status.','The order appears in WMS with expected lines, facility, and Ready status.','Escalate to WMS L2 with order number, queue message ID, and validation output.','WMS order import SKU mapping queue','Published'],
            ['SOP','FMS Load not showing',3,4,5,5,'FMS-LOAD-012','High','Load is not visible in FMS','A planned or tendered load is not visible to the expected operations team.','The load may be filtered, assigned to another business unit, delayed in integration, or missing required routing data.','Check filters and ownership, verify the outbound integration event, then replay only if the event failed.','1. Clear FMS date and status filters.\n2. Search by exact load and shipment numbers.\n3. Verify business unit and user access.\n4. Check the WMS outbound event status.\n5. Replay a failed event once and refresh FMS.','The load is visible with the expected stops and ownership.','Escalate to FMS L2 with load number, business unit, and event ID.','FMS load visibility filters integration','Published'],
            ['Error','Carrier Failed to retrieve UPS-MI-Rate',1,2,6,4,'UPS-MI-RATE-503','High','Failed to retrieve UPS-MI-Rate','Rate shopping returns no UPS Mail Innovations price for an otherwise valid parcel.','Account credentials, service eligibility, package constraints, or a temporary carrier gateway issue blocked the quote.','Validate account status and parcel eligibility, then retry after checking carrier gateway health.','1. Check Carrier Gateway status.\n2. Confirm UPS-MI credentials are active.\n3. Validate destination, weight, and package type.\n4. Remove forced service selections.\n5. Retry the rate request once.','A UPS-MI service and price are returned.','Escalate with correlation ID and sanitized rate request after confirming gateway health.','UPS MI rate retrieval credentials carrier','Published'],
            ['Article','How to request API access',5,5,8,5,'REQ-API-ACCESS','Low',null,'Standard process for requesting non-production or production API access.','Access is provisioned only after owner approval and security review.','Submit an access request with environment, business purpose, owner, and expiration date.','1. Open the service request portal.\n2. Select Integration API Access.\n3. Provide environment and business justification.\n4. Add the approving system owner.\n5. Never include secrets in the request.','The approved identity can authenticate with least-privilege scopes.','Contact Identity Operations for approved requests that exceed the stated SLA.','API access request configuration credentials','Published'],
        ];
        $sql = 'INSERT INTO knowledge_articles (type,title,system_id,module_id,category_id,carrier_id,error_code,severity,error_message,description,root_cause,resolution,sop_steps,validation_steps,escalation_path,keywords,status,owner_id,created_by,updated_by,is_sample,view_count,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,1,1,1,?,datetime(\'now\', ?),datetime(\'now\', ?))';
        $stmt = $pdo->prepare($sql);
        foreach ($articles as $index => $article) {
            $views = 148 - ($index * 13);
            $offset = '-' . ($index + 1) . ' days';
            $stmt->execute([...$article, $views, $offset, $offset]);
        }
        foreach ([[1,1],[1,4],[1,8],[2,2],[2,3],[3,4],[3,5],[4,3],[4,7],[5,6],[5,9],[6,7],[6,9],[7,5],[7,6],[8,8]] as $pair) $pdo->prepare('INSERT INTO article_tags (article_id, tag_id) VALUES (?, ?)')->execute($pair);
        foreach ([[1,4],[1,7],[2,4],[3,4],[3,7],[4,3],[5,6],[6,5],[7,1]] as $pair) $pdo->prepare('INSERT INTO related_articles (article_id, related_article_id) VALUES (?, ?)')->execute($pair);
        $version = $pdo->prepare('INSERT INTO article_versions (article_id, version_number, snapshot_json, change_summary, changed_by, created_at) VALUES (?, 1, ?, ?, 1, CURRENT_TIMESTAMP)');
        foreach ($articles as $index => $article) $version->execute([$index + 1, json_encode(['title' => $article[1], 'status' => 'Published']), 'Initial sample documentation publication']);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
