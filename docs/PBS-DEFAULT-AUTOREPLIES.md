# PBS - Default Auto-replies

## Webform - Confirmation Messages (Polylang String Translations)

### Weekend Confirmation Message - EN
Thank you for your inquiry. We will answer you on Monday.

### Weekend Confirmation Message - RU
Спасибо за ваш запрос. Мы ответим вам в понедельник.

### Weekday Message - EN
Thanks for getting in touch! Our consultant will contact you shortly.

### Weekday Message - RU
Спасибо за обращение! Наш консультант свяжется с вами в ближайшее время.

### 14 Oct Message EN
Thank you for your message!

Please note that October 14 is a non-working day. Feel free to leave your message here, and we’ll get back to you on the next business day.


### 14 Oct Message RU
Спасибо за Ваше сообщение!

14 октября — нерабочий день. Пожалуйста, оставьте Ваше сообщение здесь, и мы обязательно свяжемся с Вами в ближайший рабочий день.

---

## Telegram - Bot Messages (Sevalla messages.json)
```
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
---

## WhatsApp - respond.io Workflow messages

### Weekday EN
Thank you! 🟢
Your message has been received. A human representative from our team will personally contact you shortly.

### Weekday RU
Спасибо! 🟢
Ваше сообщение получено. Наш специалист лично свяжется с вами в ближайшее время.

### Weekday GE
გმადლობთ! 🟢
თქვენი შეტყობინება მიღებულია. ჩვენი გუნდის წარმომადგენელი მალე პირადად დაგიკავშირდებათ.

---

### Weekend EN
Welcome! 🟢

We’re currently outside of our office hours (Mon–Fri, 10:00–18:00 Tbilisi time).
Feel free to leave your message below, and our team will follow up with you as soon as business hours resume.

Thanks for reaching out!

### Weekend RU
Здравствуйте! 🟢

Сейчас мы вне рабочих часов (пн–пт, 10:00–18:00 по Тбилиси).
Пожалуйста, напишите ваш вопрос или сообщение ниже, и наша команда свяжется с вами, как только начнется рабочий день.

Спасибо за обращение!

### Weekend GE
მოგესალმებით! 🟢

ამჟამად ჩვენს სამუშაო საათებს მიღმა გვიკავშირდებით (ორშ–პარ, 10:00–18:00 თბილისის დროით).
გთხოვთ, დაგვიტოვოთ თქვენი შეტყობინება ქვემოთ და ჩვენი გუნდი დაგიკავშირდებათ სამუშაო საათების განახლებისთანავე.

გმადლობთ, რომ დაგვიკავშირდით!

### 14 Oct Message EN
Thank you for your message! 🟢
Please note that October 14 is a non-working day. Feel free to leave your message here, and we’ll get back to you on the next business day.

### 14 Oct Message RU
Спасибо за Ваше сообщение! 🟢
14 октября — нерабочий день. Пожалуйста, оставьте Ваше сообщение здесь, и мы обязательно свяжемся с Вами в ближайший рабочий день.

### 14 Oct Message GE
გმადლობთ! 🟢
გთხოვთ გაითვალისწინოთ, რომ 14 ოქტომბერი უქმე დღეა. შეგიძლიათ, თქვენი შეტყობინება აქ დატოვოთ, და ჩვენ მომდევნო სამუშაო დღეს დაგიკავშირდებით.



---

# Proposal for WS Form

What about a workflow like this for out-of-office hours?

1. Hot-lead alerts only
Have the AI score each message (ready to buy, price check, just browsing) and push a notification to your phone, via Telegram or WhatsApp, only for the hot ones. Everything else waits for the morning digest, so you’re not on call 24/7.
Use a few explicit signals—fit, intent, timing.

2. Autoreply with 2 optional links:
Thanks for reaching out! We’re offline until [day, time, time zone], and we’ll reply first thing.
📅 Want to speak sooner? [Book a meeting]
🚨 Need help before we reopen? Contact our urgent support line here: [urgent contact link]. This channel is for urgent issues that can't wait the weekend, and we respond within 12 hours.

PB Services Team
--Contacts--
