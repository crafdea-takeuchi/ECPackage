<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BackorderReady extends Notification
{
    use Queueable;

    public function __construct(public Order $order) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('取り寄せ商品が揃いました｜'.$this->order->order_number)
            ->greeting($notifiable->name.' 様')
            ->line('ご注文の取り寄せ商品がすべて揃いました。')
            ->line('注文番号：'.$this->order->order_number)
            ->action('注文内容を確認する', route('orders.show', $this->order))
            ->line('発送準備が整い次第、順次お手続きを進めます。');
    }
}
