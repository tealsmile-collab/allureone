<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/gift_helpers.php';

/**
 * @param array{ok?:bool, http?:int, body?:string} $invoiceApiResult
 *
 * @return array{invBill: ?array<string, mixed>, invErrMsg: ?string, invDataCount: int, invHttp: int}
 */
function allureone_parse_invoice_bills_api_result(array $invoiceApiResult): array
{
    $invBill = null;
    $invErrMsg = null;
    $invDataCount = 0;
    $invHttp = (int) ($invoiceApiResult['http'] ?? 0);

    if (!($invoiceApiResult['ok'] ?? false)) {
        return ['invBill' => null, 'invErrMsg' => null, 'invDataCount' => 0, 'invHttp' => $invHttp];
    }

    $invBodyRaw = (string) ($invoiceApiResult['body'] ?? '');
    $invBodyRaw = preg_replace('/^\xEF\xBB\xBF/', '', $invBodyRaw) ?? '';
    $invBodyTrimmed = trim($invBodyRaw);

    if ($invHttp < 200 || $invHttp >= 300) {
        $invErrMsg = 'API returned HTTP ' . $invHttp . '.';
        $tryErr = json_decode($invBodyTrimmed, true);
        if (is_array($tryErr)) {
            if (isset($tryErr['message']) && (string) $tryErr['message'] !== '') {
                $invErrMsg .= ' ' . (string) $tryErr['message'];
            }
        } elseif ($invBodyTrimmed !== '') {
            $invErrMsg .= ' ' . (strlen($invBodyTrimmed) > 150 ? substr($invBodyTrimmed, 0, 150) . '…' : $invBodyTrimmed);
        }

        return ['invBill' => null, 'invErrMsg' => $invErrMsg, 'invDataCount' => 0, 'invHttp' => $invHttp];
    }

    $invJsonDecoded = json_decode($invBodyTrimmed, true);
    if (!is_array($invJsonDecoded)) {
        if ($invBodyTrimmed === '') {
            $invErrMsg = 'Empty response from Dingg bills API (HTTP ' . $invHttp . ').';
        } else {
            $jsonErr = json_last_error_msg();
            $snippet = trim(preg_replace('/\s+/', ' ', strip_tags($invBodyTrimmed)) ?? '');
            if (strlen($snippet) > 180) {
                $snippet = substr($snippet, 0, 180) . '…';
            }
            $invErrMsg = 'Response was not valid JSON (' . $jsonErr . ').';
            if ($snippet !== '') {
                $invErrMsg .= ' ' . $snippet;
            }
        }

        return ['invBill' => null, 'invErrMsg' => $invErrMsg, 'invDataCount' => 0, 'invHttp' => $invHttp];
    }

    if (!dingg_bills_response_is_success($invJsonDecoded)) {
        $em = dingg_bills_api_error_message($invJsonDecoded);
        $invErrMsg = $em !== '' ? $em : 'Search failed.';

        return ['invBill' => null, 'invErrMsg' => $invErrMsg, 'invDataCount' => 0, 'invHttp' => $invHttp];
    }

    $invRows = dingg_bills_api_data_rows($invJsonDecoded);
    if ($invRows === null || $invRows === [] || count($invRows) === 0) {
        return ['invBill' => null, 'invErrMsg' => 'No invoice found for this search.', 'invDataCount' => 0, 'invHttp' => $invHttp];
    }

    $invDataCount = count($invRows);
    $invBill = $invRows[0];

    return ['invBill' => is_array($invBill) ? $invBill : null, 'invErrMsg' => null, 'invDataCount' => $invDataCount, 'invHttp' => $invHttp];
}

/**
 * @param array{invBill: ?array<string, mixed>, invErrMsg: ?string, invDataCount: int, invHttp: int} $parsed
 * @param array{ok?:bool, http?:int, body?:string} $invoiceApiResult
 */
function allureone_invoice_search_result_markup(
    array $parsed,
    array $invoiceApiResult,
    string $invoiceNoInput,
    string $csrfToken,
    string $invBranchLabel
): string {
    $invBill = $parsed['invBill'];
    $invErrMsg = $parsed['invErrMsg'];
    $invDataCount = (int) ($parsed['invDataCount'] ?? 0);

    ob_start();
    if ($invBill !== null && is_array($invBill)) {
        $billId = (int) ($invBill['id'] ?? 0);
        $billNo = (string) ($invBill['bill_number'] ?? '');
        $invDate = dingg_format_invoice_date(isset($invBill['selected_date']) ? (string) $invBill['selected_date'] : null);
        $clientName = dingg_format_invoice_client_name($invBill);
        $statusLabel = dingg_format_invoice_status_label($invBill);
        $paymentStatus = strtolower(trim((string) ($invBill['payment_status'] ?? '')));
        $isInactiveInvoice = (($invBill['status'] ?? null) === false) || str_contains(strtolower($statusLabel), 'inactive');
        $existingCancelReason = trim((string) ($invBill['cancel_reason'] ?? ''));
        $isAlreadyCancelled = $paymentStatus === 'cancelled' || $existingCancelReason !== '';
        $invoiceAmount = format_amount($invBill['total'] ?? null);
        $branchName = $invBranchLabel !== '' ? $invBranchLabel : '—';
        $branchId = 0;
        $billPayments = $invBill['bill_payments'] ?? null;
        if (is_array($billPayments)) {
            foreach ($billPayments as $bp) {
                if (!is_array($bp)) {
                    continue;
                }
                $branchId = (int) ($bp['vendor_location_id'] ?? 0);
                if ($branchId > 0) {
                    break;
                }
            }
        }
        ?>
        <div class="invoice-detail" style="margin-top:1.25rem">
            <?php if ($invDataCount > 1): ?>
                <p class="main__meta" style="margin:0 0 0.75rem">Showing first of <?= $invDataCount ?> matches.</p>
            <?php endif; ?>
            <table class="data">
                <tbody>
                    <tr><th>Branch</th><td><?= e($branchName) ?></td></tr>
                    <tr><th>Invoice number</th><td><?= $isInactiveInvoice ? '<span style="color:#b91c1c;text-decoration:line-through">' . e($billNo) . '</span>' : e($billNo) ?></td></tr>
                    <tr><th>Invoice ID</th><td><?= $billId ?></td></tr>
                    <tr><th>Invoice date</th><td><?= e($invDate) ?></td></tr>
                    <tr><th>Client name</th><td><?= e($clientName) ?></td></tr>
                    <tr><th>Total</th><td><?= e($invoiceAmount) ?></td></tr>
                    <tr><th>Status</th><td><?= $isInactiveInvoice ? '<span style="color:#b91c1c">Cancelled</span> ' . e($statusLabel) : e($statusLabel) ?></td></tr>
                </tbody>
            </table>
            <div class="invoice-detail__actions" style="margin-top:1rem;display:flex;flex-wrap:wrap;gap:0.75rem;align-items:center">
                <?php if ($billId > 0): ?>
                    <form method="post" action="dashboard.php" class="invoice-cancel-form" style="display:block;width:100%"
                          onsubmit="return confirm('Raise cancellation request for this invoice?');">
                        <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="invoice_cancel" value="1">
                        <input type="hidden" name="bill_id" value="<?= $billId ?>">
                        <input type="hidden" name="dingg_bearer" value="" class="invoice-cancel-dingg-bearer">
                        <input type="hidden" name="invoice_number" value="<?= e($billNo) ?>">
                        <input type="hidden" name="invoice_branch_name" value="<?= e($branchName) ?>">
                        <input type="hidden" name="invoice_branch_id" value="<?= $branchId ?>">
                        <input type="hidden" name="invoice_date" value="<?= e($invDate) ?>">
                        <input type="hidden" name="invoice_client_name" value="<?= e($clientName) ?>">
                        <input type="hidden" name="invoice_amount" value="<?= e($invoiceAmount) ?>">
                        <input type="hidden" name="invoice_status" value="<?= e($statusLabel) ?>">
                        <div style="width:100%;margin:0.25rem 0 0.5rem">
                            <label for="cancel_reason_<?= $billId ?>" style="display:block;margin-bottom:0.35rem">Cancellation reason <span class="required-mark" aria-hidden="true">*</span></label>
                            <textarea id="cancel_reason_<?= $billId ?>" name="cancel_reason" maxlength="100" rows="3"
                                      required aria-required="true"<?= $isAlreadyCancelled ? ' readonly aria-readonly="true"' : '' ?>
                                      placeholder="Enter reason (required, max 100 characters)"
                                      style="width:100%;height:80px;box-sizing:border-box;resize:vertical"><?= e($existingCancelReason) ?></textarea>
                        </div>
                        <div style="display:flex;gap:0.75rem;align-items:center">
                            <?php if (!$isAlreadyCancelled): ?>
                                <button type="submit" class="btn btn--danger invoice-cancel-submit">Request Cancellation</button>
                            <?php endif; ?>
                            <button type="button" class="btn btn--ghost invoice-search-reset">Clear</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <?php
    } elseif ($invErrMsg !== null) {
        ?>
        <p class="alert alert--error" style="margin-top:1rem;margin-bottom:0"><?= e($invErrMsg) ?></p>
        <p style="margin-top:0.75rem;margin-bottom:0"><button type="button" class="btn btn--ghost invoice-search-reset">Back</button></p>
        <?php
    } else {
        ?>
        <div class="invoice-api-out" style="margin-top:1rem">
            <p class="main__meta" style="margin:0 0 0.5rem">HTTP <?= (int) ($invoiceApiResult['http'] ?? 0) ?> · term <?= e($invoiceNoInput) ?></p>
            <pre class="invoice-api-json"><?= e((string) ($invoiceApiResult['body'] ?? '')) ?></pre>
        </div>
        <p style="margin-top:0.75rem;margin-bottom:0"><button type="button" class="btn btn--ghost invoice-search-reset">Back</button></p>
        <?php
    }

    return (string) ob_get_clean();
}
