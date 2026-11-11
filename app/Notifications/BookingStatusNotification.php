<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Support\Payments\BookingPaymentSummary;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Must stay synchronous (never ShouldQueue): SendCustomerBookingNotice checks the booking's version and status just
 * before sending, and that check is what keeps this email truthful.
 */
final class BookingStatusNotification extends Notification
{
    public function __construct(private readonly Booking $booking, private readonly string $kind) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // The notification sender switches the app to this notification's locale while rendering.
        $booking = $this->booking;
        $english = app()->getLocale() === 'en';
        $values = [
            'reference' => $booking->reference,
            'service' => ($english ? $booking->package_name_en : $booking->package_name_id) ?: ($booking->package_name_en ?: $booking->package_name_id),
            'date' => $booking->appointment_date->copy()->locale(app()->getLocale())->translatedFormat('j F Y'),
            'time' => substr((string) $booking->appointment_time, 0, 5).' WIB',   // every appointment is in Asia/Jakarta
        ];

        $mail = (new MailMessage)
            ->subject(__("mail.booking.{$this->kind}.subject"))
            ->greeting(__('mail.greeting', ['name' => $booking->customer_name]))
            ->line(__("mail.booking.{$this->kind}.intro", $values));

        $closing = match ($this->kind) {
            'approved' => app(BookingPaymentSummary::class)->forBooking($booking)['state'] === 'at_spa_unrecorded'   // mutation P
                ? __('mail.booking.pay_at_spa') : null,
            'cancelled' => __('mail.booking.cancelled.again'),
            'no_show' => __('mail.booking.no_show.contact'),
        };

        if ($closing !== null) {
            $mail->line($closing);
        }

        // The customer's own booking page, which needs their sign-in; staff-entered bookings have no customer account.
        // Never an admin link. The root comes from APP_URL: a queue worker has no request to take it from.
        if ($booking->user_id !== null) {   // mutation L
            $mail->action(__('mail.booking.action'), rtrim((string) config('app.url'), '/').route('bookings.show', $booking->reference, false));
        }

        return $mail;
    }
}
