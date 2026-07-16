<?php

namespace Botble\Payment\Enums;

use Botble\Base\Facades\BaseHelper;
use Botble\Base\Supports\Enum;
use Illuminate\Support\HtmlString;

/**
 * @method static PaymentStatusEnum PENDING()
 * @method static PaymentStatusEnum COMPLETED()
 * @method static PaymentStatusEnum CLOSED()
 * @method static PaymentStatusEnum REFUNDING()
 * @method static PaymentStatusEnum REFUNDED()
 * @method static PaymentStatusEnum FRAUD()
 * @method static PaymentStatusEnum FAILED()
 */
class PaymentStatusEnum extends Enum
{
    public const PENDING = 'pending';

    public const COMPLETED = 'completed';

    public const CLOSED = 'closed';

    public const REFUNDING = 'refunding';

    public const REFUNDED = 'refunded';

    public const FRAUD = 'fraud';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    public static $langPath = 'plugins/payment::payment.statuses';

    public function toHtml(): HtmlString|string
    {
        $color = match ($this->value) {
            self::PENDING, self::REFUNDING => 'warning',
            self::COMPLETED, self::CLOSED => 'success',
            self::REFUNDED => 'info',
            self::FRAUD, self::FAILED => 'danger',
            default => 'primary',
        };

        return BaseHelper::renderBadge($this->label(), $color);
    }
}
