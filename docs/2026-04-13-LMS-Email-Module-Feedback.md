# LMS Email Module — Meeting Feedback & Action Items
**Date:** 2026-04-13 (Sunday)
**Attendees:** Mateo Gonzalez, Yuyan Huang, Christopher Love

---

## A. Email Workflow Engine — Changes Required

### A.1 Split coaching session status trigger into sub-statuses
- **Requested by:** Mateo (self-identified)
- **Detail:** The "coaching session status changed" trigger should be split so each sub-status (e.g., "session booked," "session completed") is a distinct trigger option rather than one combined trigger.
- **Quote:** *"It's weird, it was supposed to be split in two, which status did it change to, but let's do it like that for now."*

### A.2 Fix enrollment status condition options
- **Requested by:** Mateo (self-identified)
- **Detail:** The condition dropdown for enrollment status in the workflow builder has incorrect/incomplete options.
- **Quote:** *"I need to fix some options here."*

### A.3 Make trigger day-offset configurable (not hardcoded to 7 days)
- **Requested by:** Mateo, confirmed by Yuyan and Chris
- **Detail:** Currently the "coaching window 7 days" trigger is hardcoded. The offset must be a user-configurable number (e.g., 5 days before, 3 days before, 1 day before). Applies to coaching sessions, RP sections, and classroom visits at minimum.
- **Quote (Mateo):** *"It's more like adding the days so that it's customizable, like 5 days before, three days before."*
- **Priority:** HIGH — Mateo committed to having this ready by the next morning.

### A.4 Use display window start date for coaching sessions; release date AND complete-by date for all other components
- **Requested by:** Yuyan (primary), agreed by all
- **Detail:** The date anchor for email triggers differs by component type:
  - **Coaching sessions** → display window start date (aligned with pacing guide)
  - **All other components** → both release date AND complete-by date must be available as trigger anchors
- Yuyan explicitly pushed back against using only complete-by date, because pacing differs per partnership and the communication spreadsheets include triggers relative to both release and complete-by dates.
- **Quote (Yuyan):** *"It's the release day and a complete by date, Mateo, because we do have triggers for, like, seven days before the classroom visit is released... the pacing guide would be different, so I'd rather use the specific field that we already have the data in."*
- **Priority:** HIGH — foundational trigger logic decision.

### A.5 Add "coaching session not yet scheduled" condition
- **Requested by:** Mateo (identified gap), Chris (confirmed need)
- **Detail:** For reminder sequences (7-day, 3-day, 1-day nudges), the system needs a condition that checks whether the coaching session has already been scheduled. Without it, reminders fire even after scheduling.
- **Quote (Mateo):** *"It still won't work because you would need a condition like: only send it if the coaching session has not been scheduled yet."*
- **Priority:** HIGH — core to coaching session communication flow.

### A.6 Remove/hide "Submission Window" field from the UI
- **Requested by:** Yuyan, agreed by Chris
- **Detail:** The separate "submission window" (open/close dates) per component is redundant with the display window / release / complete-by dates. It adds maintenance burden and risk of data entry errors. Remove from the admin-facing UI.
- **Quote (Yuyan):** *"I think we should just use the display window so we don't need to enter multiple dates for different things. It will be hard to maintain. More errors may happen."*
- **Open question:** Mateo raised whether the field should be kept in the backend as a safety guard against stale emails firing months later. Needs a decision.

### A.7 Add workflow folders/groups
- **Requested by:** Mateo (proposed), Chris and Yuyan agreed
- **Detail:** Add folder/group organization in the workflow list so related workflows (e.g., all coaching session notifications) can be visually grouped together.
- **Quote (Mateo):** *"Adding some sort of folders here, or something where you can group like coaching session notifications, and then you can add multiple workflows there."*

### A.8 Add workflow cloning/duplication
- **Requested by:** Mateo (proposed), Chris and Yuyan agreed
- **Detail:** Ability to clone an existing workflow so variants (5 days before, 3 days before, etc.) can be created quickly without rebuilding from scratch.
- **Quote (Mateo):** *"Maybe just being able to clone them. So 5 days before, three days before, two days before."*

### A.9 Emails must be evergreen (not partner-specific)
- **Requested by:** Chris (stated as foundational constraint)
- **Detail:** Email workflows are built once and run globally across all partnerships. They should NOT need per-partner configuration.
- **Quote (Chris):** *"This is going to be what is referred to as like evergreen, right? Like we're going to build these communications one time and then they will run."*
- **Priority:** HIGH — architectural constraint to validate against.

---

## B. Email Template Builder — Changes Required

### B.1 Fix white font / text color bug in email preview
- **Requested by:** Mateo (self-identified bug)
- **Detail:** The email preview has a font color rendering issue (white text on light background or similar). Mateo flagged it as one of two current blockers.
- **Quote (Mateo):** *"I need to fix this font... the white font. I think those are the two only things for now that could probably stop you from doing something."*
- **Priority:** HIGH — blocker for Chris to start building emails.

### B.2 Display coaching session info in email preview
- **Requested by:** Mateo (self-identified), Chris confirmed need
- **Detail:** The email preview does not currently populate coaching session merge tags (date, time, Zoom link) because it requires a real scheduled session to pull data from. Need to either show sample data or allow selecting a real session for preview.
- **Quote (Mateo):** *"We would probably need to select a specific coaching session, like a real coaching session that has been scheduled so that we can see it."*

### B.3 Add merge tag quick-insert to button URL field
- **Requested by:** Mateo (self-identified gap)
- **Detail:** The merge tag picker does not work in the button URL field — users must manually type merge tag syntax. This needs to be fixed so CTA buttons can easily include dynamic URLs (Zoom link, coaching schedule URL, etc.).
- **Quote (Mateo):** *"I also need to make sure that we include a way of quickly adding merge tags in the button field because... the merge tag section is not allowing us to copy."*
- **Priority:** HIGH — blocks proper button setup for coaching session emails.

### B.4 Coaching session merge tags (date/time, Zoom link, schedule URL)
- **Requested by:** Chris
- **Detail:** Merge tags for coaching session date/time, Zoom link, and coaching schedule URL must be available in the email body and button fields. Mateo confirmed these tags exist but need testing with real data.
- **Quote (Chris):** *"Can you put in like coaching session, date, time? Zoom link."*
- **Quote (Mateo):** *"Look, it's right here, coaching schedule URL, yeah."*
- **Status:** Tags exist. Need to verify they populate correctly with real session data.

---

## C. Email System Operations

### C.1 Enable test email sending (restricted to internal addresses)
- **Requested by:** Yuyan
- **Detail:** Email sending is currently fully disabled. Enable it with a safety conditional: only allow sends to `@housmanlearning.com` or known job-mail addresses so the team can test workflows without risk.
- **Quote (Mateo):** *"I can add a conditional, like only send emails to Housman learning.com or job mail email addresses for now, so that you can send the test emails."*
- **Priority:** HIGH — blocks all testing.

### C.2 Sender address: academy@housmanlearning.com
- **Confirmed by:** Yuyan
- **Detail:** The sender address for all LMS emails is `academy@housmanlearning.com`. This is a monitored inbox (Yuyan, Mateo, and soon Angela). Users sometimes reply to system emails.
- **Quote (Yuyan):** *"Academy at Housman learning.com... it should be monitored regularly."*

### C.3 Grant Angela access to academy@housmanlearning.com inbox
- **Requested by:** Yuyan (previously requested, pending)
- **Detail:** Angela needs access to the shared inbox so she can monitor replies and password reset notifications.
- **Quote (Yuyan):** *"I also asked Mateo to grant access to Angela to that email inbox."*
- **Action owner:** Mateo

### C.4 Chris will likely be the primary email workflow builder
- **Stated by:** Chris
- **Detail:** Chris expects to be the one building email workflows since Yuyan has other priorities and his team lacks backend access. May need onboarding/guidance.
- **Quote (Chris):** *"It's probably going to be me building these because Yuyan has other stuff going on and none of my team members have access to the back end."*

---

## D. Future Feature Requests (Not Immediate)

### D.1 SMS notifications
- **Requested by:** Chris
- **Detail:** Certain reminders (e.g., coaching session) should also go via SMS for users who have opted in and provided a phone number. Mateo confirmed feasibility and low cost (~$0.008/message, ~$20/month at their volume). Zoho may already support SMS at no extra cost.
- **Quote (Chris):** *"Ideally we'd be able to set certain reminders to go through SMS as well. If the user has opted in to SMS."*
- **Open question:** Does a phone number field + SMS opt-in checkbox already exist in user profiles? If not, those need to be built too.
- **Priority:** Lower — future feature.

### D.2 Teams phone calling + Zoho CRM call logging
- **Requested by:** Chris (on behalf of Jonathan)
- **Detail:** Team needs to make work calls without personal devices. Calls should be logged in Zoho CRM. Automatic tracking is complex (call center infra); manual logging after calls is acceptable.
- **Quote (Chris):** *"Jonathan wants it to integrate with Zoho, so that there's a record of the calls in Zoho."*
- **Quote (Chris):** *"That's fine. That's like a once we have more clients thing."*
- **Priority:** Low — explicitly described as lower priority.

---

## E. Sales Demo Environment

### E.1 Create a separate demo WordPress installation
- **Requested by:** Chris (on behalf of Preston from sales)
- **Detail:** Sales needs a demo environment to walk prospects through the LMS. Specifications agreed:
  - Separate WordPress install (e.g., `demo.housmanlearning.com`) — NOT production
  - Fake/anonymized data only — no real PII
  - 3-4 demo partnerships with test data
  - One cycle fully interactive, remaining cycles visible but locked
  - Multiple role perspectives: teacher, mentor, school leader, district leader, administrator
  - Ability to reset to defaults
  - No backend access for sales reps
- **Quote (Chris):** *"Just wondering if there's a way to create like a sales demo that they can use."*
- **Quote (Chris):** *"Just the ability to toggle between those end user roles."*
- **Quote (Mateo):** *"I honestly wouldn't want sales going into our production site... just making an exact copy of this, remove all real people information... whenever they want they can just reset it to default."*
- **Priority:** HIGH for sales team. Mateo estimated ~2 days, possibly ready by Thursday.
- **Open question:** How to implement role-toggling — separate user accounts per role, or a "view as" switcher UI?

---

## F. Administrative / Non-Development Items

### F.1 Mateo invited to weekly Monday team meeting
- **Requested by:** Chris
- **Detail:** Standing invitation to Housman's weekly Monday cross-department meeting. Mateo accepted.

### F.2 Add Mateo to Asana meeting agenda project
- **Requested by:** Chris
- **Detail:** Chris will add Mateo to the Asana project used for meeting agendas.

### F.3 Add corsox.com domain to Housman Learning Asana organization
- **Requested by:** Mateo (to Yuyan)
- **Detail:** Yuyan will add `corsox.com` to Asana org email domain management so Mateo gets full member permissions and API access (not guest). Requires DNS TXT record from Mateo first.
- **Action:** Mateo sends Yuyan the TXT record details.

### F.4 Send Chris a link for a Corsox review
- **Requested by:** Chris
- **Quote:** *"Don't forget to send me a place where I can write you a really nice review."*
- **Action owner:** Mateo

---

## G. Items Needing Clarification

| # | Topic | Question |
|---|-------|----------|
| G.1 | Submission Window field | Keep in backend as safety guard, or remove entirely from the data model? |
| G.2 | Release date field availability | Do all component types (CV, RP, action plan, etc.) actually have a `release_date` column in the DB? |
| G.3 | Dynamic day-offset scope | First pass covers coaching/RP/CV — should all component types get dynamic offsets from the start, or is a phased rollout OK? |
| G.4 | SMS opt-in mechanism | Does a phone number field + opt-in checkbox exist in user profiles, or does that need to be built? |
| G.5 | Demo site role-toggling | Separate user accounts per role, or a "view as" UI switcher? |
| G.6 | White font bug location | Where exactly does the white font appear — editor, preview panel, or rendered email output? |
| G.7 | Coaching session merge tags | Are these fully implemented (just need real data to test) or do they need backend work? |
| G.8 | Chris's admin access | Does Chris need onboarding or specific permissions to build email workflows in the backend? |
