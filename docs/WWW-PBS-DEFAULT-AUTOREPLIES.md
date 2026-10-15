# PBS Default Auto-replies

Content reference for PBS brand auto-reply message templates across all channels.

## Webform — Confirmation Messages (Polylang String Translations)

### Weekend Confirmation — EN
Thank you for your inquiry. We will answer you on Monday.

### Weekend Confirmation — RU
Спасибо за ваш запрос. Мы ответим вам в понедельник.

### Weekday — EN
Thanks for getting in touch! Our consultant will contact you shortly.

### Weekday — RU
Спасибо за обращение! Наш консультант свяжется с вами в ближайшее время.

### 14 Oct — EN
Thank you for your message!

Please note that October 14 is a non-working day. Feel free to leave your message here, and we'll get back to you on the next business day.

### 14 Oct — RU
Спасибо за Ваше сообщение!

14 октября — нерабочий день. Пожалуйста, оставьте Ваше сообщение здесь, и мы обязательно свяжемся с Вами в ближайший рабочий день.

## Telegram — Bot Messages (Sevalla messages.json)

```json
{
  "welcome": {
    "default": "Welcome to PB Services! 👋\nSend your message below to connect with our support team.",
    "ru": "Добро пожаловать в PB Services! 👋\nНапишите ваше сообщение ниже, чтобы связаться с нашей поддержкой."
  },
  "autoreply": {
    "default": "Hi {name}, thank you for reaching out to us! 🟢\nWe've received your message, and one of our team members will contact you shortly.",
    "ru": "Здравствуйте, {name}! Спасибо, что обратились к нам! 🟢\nМы получили ваше сообщение, и один из наших специалистов свяжется с вами в ближайшее время."
  },
  "autoreply_after_hours": {
    "default": "Welcome! 🟢\n\nWe're currently outside of our office hours (Mon–Fri, 10:00–18:00 Tbilisi time).\nFeel free to leave your message below, and our team will follow up with you as soon as business hours resume.\n\nThanks for reaching out!",
    "ru": "Добро пожаловать! 🟢\n\nСейчас нерабочее время (Пн–Пт, 10:00–18:00 по тбилисскому времени).\nОставьте, пожалуйста, ваше сообщение здесь, и наша команда свяжется с вами, как только начнётся рабочий день.\n\nСпасибо за обращение!"
  },
  "relay": {
    "sessionOpen": "🔄 *Relay session open*\nReplying to user `{userId}`.\nType your messages here — they will be sent to the user.\nSend /close to end the session.",
    "sent": "✅ Sent",
    "closed": "🔒 Relay session closed."
  }
}
```

## WhatsApp — respond.io Workflow Messages

### Weekday

**EN:** Thank you! 🟢 Your message has been received. A human representative from our team will personally contact you shortly.

**RU:** Спасибо! 🟢 Ваше сообщение получено. Наш специалист лично свяжется с вами в ближайшее время.

**GE:** გმადლობთ! 🟢 თქვენი შეტყობინება მიღებულია. ჩვენი გუნდის წარმომადგენელი მალე პირადად დაგიკავშირდებათ.

### Weekend

**EN:** Welcome! 🟢 We're currently outside of our office hours (Mon–Fri, 10:00–18:00 Tbilisi time). Feel free to leave your message below, and our team will follow up with you as soon as business hours resume. Thanks for reaching out!

**RU:** Здравствуйте! 🟢 Сейчас мы вне рабочих часов (пн–пт, 10:00–18:00 по Тбилиси). Пожалуйста, напишите ваш вопрос или сообщение ниже, и наша команда свяжется с вами, как только начнется рабочий день. Спасибо за обращение!

**GE:** მოგესალმებით! 🟢 ამჟამად ჩვენს სამუშაო საათებს მიღმა გვიკავშირდებით (ორშ–პარ, 10:00–18:00 თბილისის დროით). გთხოვთ, დაგვიტოვოთ თქვენი შეტყობინება ქვემოთ და ჩვენი გუნდი დაგიკავშირდებათ სამუშაო საათების განახლებისთანავე. გმადლობთ, რომ დაგვიკავშირდით!

### 14 Oct

**EN:** Thank you for your message! 🟢 Please note that October 14 is a non-working day. Feel free to leave your message here, and we'll get back to you on the next business day.

**RU:** Спасибо за Ваше сообщение! 🟢 14 октября — нерабочий день. Пожалуйста, оставьте Ваше сообщение здесь, и мы обязательно свяжемся с Вами в ближайший рабочий день.

**GE:** გმადლობთ! 🟢 გთხოვთ გაითვალისწინოთ, რომ 14 ოქტომბერი უქმე დღეა. შეგიძლიათ, თქვენი შეტყობინება აქ დატოვოთ, და ჩვენ მომდევნო სამუშაო დღეს დაგიკავშირდებით.

## WS Form — Out-of-Office Proposal

1. Add an autoreply by email after form submission.
2. Message with 2 links (example for weekend):

```
  Thanks for reaching out! 
  We're offline until Monday, and we'll reply first thing.
  - 📅 Want to speak sooner? [Book a meeting] (Calendly)
  - 🚨 Need help before we reopen? Contact our urgent support line here: [contact link]. This channel is only for urgent issues that can't wait the weekend, and we respond within 12 hours.

The PB Services Team
```

The second link is optional, it would be nice-to-have, but obviously difficult for lack of dedicated team member.


1. **Hot-lead alerts only** — AI scores each message (ready to buy, price check, just browsing) and pushes notifications only for hot leads via Telegram/WhatsApp. Everything else waits for morning digest.
2. **Autoreply with optional links:**
   - Thanks for reaching out! We're offline until [day, time, time zone], and we'll reply first thing.
   - 📅 Want to speak sooner? [Book a meeting]
   - 🚨 Need help before we reopen? Contact our urgent support line here: [urgent contact link]. This channel is for urgent issues that can't wait the weekend, and we respond within 12 hours.