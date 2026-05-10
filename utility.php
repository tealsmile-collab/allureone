<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();
require_not_franchise_officer_role();

$pageTitle = 'Utility';
$activeNav = 'utility';
$directPaymentLink = 'https://razorpay.me/@allurethai';
require __DIR__ . '/includes/layout_start.php';
?>

<div class="card">
    <div class="card__head">
        <span>Utility</span>
    </div>
    <div class="card__body" style="padding:1rem 1.25rem">
        <div style="display:flex;align-items:center;justify-content:flex-start;gap:0.2rem;max-width:42rem;margin:0 0 0.75rem">
            <a href="<?= e($directPaymentLink) ?>" class="link--underlined" target="_blank" rel="noopener noreferrer"><?= e('Direct Payment Link - ' . $directPaymentLink) ?></a>
            <button type="button"
                    class="btn btn--ghost"
                    id="utility-copy-direct-pay-link"
                    data-copy-url="<?= e($directPaymentLink) ?>"
                    aria-label="Copy direct payment link"
                    title="Copy link"
                    style="margin:0;padding:0.25rem;line-height:0;border:none;min-width:auto;flex-shrink:0">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                    <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                </svg>
            </button>
        </div>
        <p id="utility-copy-feedback" role="status" style="margin:-0.5rem 0 0.75rem;font-size:0.875rem;color:var(--muted, #64748b);display:none;max-width:42rem"></p>
        <h3 style="margin:0 0 0.75rem;font-size:1rem">Payment Check</h3>
        <form id="utility-payment-check-form" style="display:flex;align-items:center;gap:0.75rem;flex-wrap:wrap;max-width:42rem;margin:0">
            <input type="text"
                   id="utility-rzp-payment-id"
                   name="payment_id"
                   maxlength="50"
                   required
                   aria-required="true"
                   autocomplete="off"
                   placeholder="Razorpay payment or order id"
                   title="Required"
                   style="flex:1;min-width:12rem;max-width:100%;padding:0.45rem 0.55rem">
            <button type="submit" class="btn btn--primary" id="utility-rzp-check-btn">Check Status</button>
        </form>
    </div>
</div>

<div id="rzp-status-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:2000;align-items:center;justify-content:center;padding:1rem">
    <div style="background:#fff;border-radius:10px;max-width:640px;width:100%;max-height:80vh;overflow:auto;border:1px solid #d6dde6">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:0.8rem 1rem;border-bottom:1px solid #d6dde6">
            <strong id="rzp-status-modal-title">Razorpay status</strong>
            <button type="button" id="rzp-status-close" class="btn btn--ghost" style="padding:0.3rem 0.65rem">Close</button>
        </div>
        <div id="rzp-status-content" style="padding:1rem"></div>
    </div>
</div>

<script>
(function () {
    var csrf = <?= json_encode(csrf_token()) ?>;
    var rzpModal = document.getElementById('rzp-status-modal');
    var rzpClose = document.getElementById('rzp-status-close');
    var rzpContent = document.getElementById('rzp-status-content');
    var checkBtn = document.getElementById('utility-rzp-check-btn');
    var paymentInput = document.getElementById('utility-rzp-payment-id');
    var paymentForm = document.getElementById('utility-payment-check-form');
    var modalTitle = document.getElementById('rzp-status-modal-title');

    function showRzpModal(html) {
        if (!rzpModal || !rzpContent) return;
        rzpContent.innerHTML = html;
        rzpModal.style.display = 'flex';
    }

    function hideRzpModal() {
        if (!rzpModal || !rzpContent) return;
        rzpModal.style.display = 'none';
        rzpContent.innerHTML = '';
    }

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = String(s || '');
        return d.innerHTML;
    }

    if (rzpClose) {
        rzpClose.addEventListener('click', hideRzpModal);
    }
    if (rzpModal) {
        rzpModal.addEventListener('click', function (ev) {
            if (ev.target === rzpModal) hideRzpModal();
        });
    }

    function runCheck() {
        if (!checkBtn || !paymentInput) return;
        if (!paymentInput.checkValidity()) {
            paymentInput.reportValidity();
            return;
        }
        var paymentId = String(paymentInput.value || '').trim();
        if (!paymentId) {
            showRzpModal('<p class="alert alert--error" style="margin:0">Payment id is required.</p>');
            return;
        }
        if (modalTitle) modalTitle.textContent = 'Razorpay status';
        checkBtn.disabled = true;
        var oldText = checkBtn.textContent;
        checkBtn.textContent = 'Checking...';

        fetch('razorpay_payment_status_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ payment_id: paymentId, _csrf: csrf })
        })
            .then(function (r) {
                return r.text().then(function (text) {
                    var j = null;
                    try { j = JSON.parse(text); } catch (e) {}
                    return { j: j, raw: text };
                });
            })
            .then(function (j) {
                if (!j || !j.j || j.j.ok !== true || !j.j.data) {
                    var em = (j && j.j && j.j.error) ? j.j.error : '';
                    if (!em && j && j.raw) {
                        em = String(j.raw).trim().slice(0, 300);
                    }
                    if (!em) em = 'Could not fetch Razorpay status.';
                    showRzpModal('<p class="alert alert--error" style="margin:0">' + esc(em) + '</p>');
                    return;
                }
                var d = j.j.data;
                var recordType = String(d.record_type || 'payment');
                if (modalTitle) {
                    modalTitle.textContent = recordType === 'order' ? 'Razorpay Order' : 'Razorpay Payment Status';
                }
                var rows;
                if (recordType === 'order') {
                    var orderStatusRaw = String(d.payment_status || '');
                    var orderStatusHtml = esc(orderStatusRaw);
                    if (orderStatusRaw.toLowerCase() === 'paid') {
                        orderStatusHtml = '<span style="color:#166534;font-weight:600">Successful (paid)</span>';
                    }
                    rows =
                        '<table class="data"><tbody>' +
                        '<tr><th>Type</th><td>Razorpay Order</td></tr>' +
                        '<tr><th>Order Id</th><td>' + esc(d.order_id) + '</td></tr>' +
                        '<tr><th>Order status</th><td>' + orderStatusHtml + '</td></tr>' +
                        '<tr><th>Amount</th><td>' + esc(d.amount) + '</td></tr>' +
                        '<tr><th>Amount paid</th><td>' + esc(d.amount_paid) + '</td></tr>' +
                        '<tr><th>Amount due</th><td>' + esc(d.amount_due) + '</td></tr>' +
                        '<tr><th>Currency</th><td>' + esc(d.currency) + '</td></tr>' +
                        '<tr><th>Receipt</th><td>' + esc(d.receipt) + '</td></tr>' +
                        '<tr><th>Attempts</th><td>' + esc(String(d.attempts != null ? d.attempts : '')) + '</td></tr>' +
                        '</tbody></table>';
                } else {
                    var statusHtml = esc(d.payment_status);
                    if (String(d.payment_status || '').toLowerCase() === 'captured') {
                        statusHtml = '<span style="color:#166534;font-weight:600">Successful (captured)</span>';
                    } else if (d.has_error) {
                        statusHtml = '<span style="color:#b91c1c;font-weight:600">' + esc(d.payment_status || 'error') + '</span>';
                    }
                    rows =
                        '<table class="data"><tbody>' +
                        '<tr><th>Payment Id</th><td>' + esc(d.payment_id) + '</td></tr>' +
                        '<tr><th>Order Id</th><td>' + esc(d.order_id) + '</td></tr>' +
                        '<tr><th>Payment Status</th><td>' + statusHtml + '</td></tr>' +
                        '<tr><th>Amount</th><td>' + esc(d.amount) + '</td></tr>' +
                        '<tr><th>Contact</th><td>' + esc(d.contact) + '</td></tr>' +
                        '<tr><th>Email</th><td>' + esc(d.email) + '</td></tr>' +
                        '<tr><th>Payment Method</th><td>' + esc(d.payment_method) + '</td></tr>';
                    if (d.has_error) {
                        rows +=
                            '<tr><th>Error</th><td>' + esc(d.error_description) + '</td></tr>' +
                            '<tr><th>Error Reason</th><td>' + esc(d.error_reason) + '</td></tr>' +
                            '<tr><th>Error Step</th><td>' + esc(d.error_step) + '</td></tr>';
                    }
                    rows += '</tbody></table>';
                }
                showRzpModal(rows);
            })
            .catch(function () {
                showRzpModal('<p class="alert alert--error" style="margin:0">Network error while checking payment status.</p>');
            })
            .finally(function () {
                checkBtn.disabled = false;
                checkBtn.textContent = oldText;
            });
    }

    if (paymentForm) {
        paymentForm.addEventListener('submit', function (ev) {
            ev.preventDefault();
            runCheck();
        });
    }

    var copyBtn = document.getElementById('utility-copy-direct-pay-link');
    var copyFeedback = document.getElementById('utility-copy-feedback');
    if (copyBtn) {
        copyBtn.addEventListener('click', function () {
            var url = String(copyBtn.getAttribute('data-copy-url') || '').trim();
            if (!url) return;
            function showMsg(msg) {
                if (!copyFeedback) return;
                copyFeedback.textContent = msg;
                copyFeedback.style.display = 'block';
                window.setTimeout(function () {
                    copyFeedback.style.display = 'none';
                }, 2500);
            }
            function doFallback() {
                var ta = document.createElement('textarea');
                ta.value = url;
                ta.style.position = 'fixed';
                ta.style.left = '-9999px';
                document.body.appendChild(ta);
                ta.select();
                try {
                    document.execCommand('copy');
                    showMsg('Link copied.');
                } catch (err) {
                    showMsg('Could not copy.');
                }
                document.body.removeChild(ta);
            }
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(function () {
                    showMsg('Link copied.');
                }).catch(function () {
                    doFallback();
                });
            } else {
                doFallback();
            }
        });
    }
})();
</script>

<?php require __DIR__ . '/includes/layout_end.php'; ?>
