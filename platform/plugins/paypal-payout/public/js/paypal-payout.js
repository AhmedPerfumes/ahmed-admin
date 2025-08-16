/******/ (() => { // webpackBootstrap
/******/ 	"use strict";
/*!*****************************************************************************!*\
  !*** ./platform/plugins/paypal-payout/resources/assets/js/paypal-payout.js ***!
  \*****************************************************************************/


$(document).ready(function () {
  $(document).on('click', '.btn-payout-button', function (event) {
    event.preventDefault();
    event.stopPropagation();
    var $this = $(event.currentTarget);
    $this.addClass('button-loading');
    $.ajax({
      type: 'POST',
      url: $this.prop('href'),
      success: function success(res) {
        if (!res.error) {
          Botble.showSuccess(res.message);
          $this.closest('.widget.meta-boxes').remove();
          window.location.reload();
        } else {
          Botble.showError(res.message);
        }
        $this.removeClass('button-loading');
      },
      error: function error(res) {
        $this.removeClass('button-loading');
        Botble.handleError(res);
      }
    });
  });
  var loadPayPalPayoutInfo = function loadPayPalPayoutInfo() {
    var $transactionDetail = $('#payout-transaction-detail');
    $.ajax({
      type: 'GET',
      url: $transactionDetail.data('url'),
      success: function success(res) {
        if (!res.error) {
          $transactionDetail.html(res.data.html);
        } else {
          $transactionDetail.html('');
        }
      },
      error: function error(res) {
        console.log(res);
      }
    });
  };
  loadPayPalPayoutInfo();
});
/******/ })()
;