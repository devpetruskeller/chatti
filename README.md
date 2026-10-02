# PosTooChat Chatti — Workspace and Routed Chat Plan

## 1. Purpose and scope

**Chatti** provides the workspace, team-role, availability, and routed-chat
workflow for businesses and individuals using PosTooChat. A workspace can serve
a sole proprietor, a small household business, an owner working with an AI, or
a larger company with administrators and workers.

Chatti owns workspace onboarding, role invitations, working availability,
entry links, request routing, worker chat assignment, and administrator
monitoring. It does not replace the separate initial consent required when a
person joins the PosTooChat WhatsApp or Telegram channel.

The current end-to-end design is maintained in
[dialog.mer](dialog.mer).

## Registry setup

Before Chatti can receive a verified Suite selection or send
`CHATTI_ONBOARDING`, register it through **Tools → Chat App Registry**. Use:

```text
App ID: chatti
Label: Chatti
Channels: WhatsApp, Telegram
Entry words: CHATTI
Callback URL: https://api.postoochat.com/wp-json/chatti/v1/events
Callback secret environment variable: PT_CALLBACK_SIGNING_SECRET_CHATTI
Key ID: chatti-wp-2026-01
Message group: postoochat_chatti
```

Leave Tenant UUID blank in the Registry to create a dedicated Chatti tenant.
Create the named callback secret in Supabase and put the same secret value in Chatti's private WordPress
configuration as `PTC_CHATTI_CALLBACK_SIGNING_SECRET`. The one-time delivery
API key returned by the Registry belongs in `PTC_CHATTI_APP_API_KEY`, while its
Key ID belongs in `PTC_CHATTI_APP_KEY_ID`.

The plugin also needs the private Edge Function URLs in
`PTC_CHATTI_TIMEZONE_LOOKUP_URL` and `PTC_CHATTI_INVITATIONS_URL`. It sends
the same Chatti application key to these services; it never creates or stores
an invitation secret locally.

`CHATTI_ONBOARDING` must be published for both WhatsApp and Telegram in the
`postoochat_chatti` Message Center group, then imported into Supabase using
**Notify Supabase**. If the active catalog does not contain that message, Chatti
returns a terminal 422 to Suite; Suite immediately sends its standard
under-construction seed fallback instead of retrying the selection callback.

## 2. Workspace roles and hierarchy

| Role | Responsibility | User-facing chat |
| --- | --- | --- |
| Owner | Creates the workspace and owns its administration. | Only while also holding the Worker role. |
| Admin | Administers invitations, worker availability, and worker-conversation monitoring. | No. |
| Worker | Receives routed requests and carries the user conversation. | Yes. |
| User | Enters through a workspace QR code, HTML code, or link to request help. | With a Worker only. |

The role hierarchy is **Owner → Admin → Worker → User**.

The person who creates a workspace starts with the Owner role only and is the
only person permitted to complete this setup. A Worker is the only role
permitted to hold a user-facing conversation.

## 3. Workspace onboarding

1. The founding Owner names the already-created workspace.
2. Chatti sends the User invitation and its channel-specific link. The Owner
   confirms **Link Copied** before the next onboarding step opens.
3. The Owner shares a location pin; Supabase records it as `workspace_base`
   and returns its IANA timezone.
4. The Owner selects an hours action or types `08:00 - 17:00`.
5. Chatti sends the Admin invitation and link, then the Worker invitation and
   link, and finally opens `CHATTI_MENU`.
6. A Worker publishes a workspace entry link, QR code, or HTML code. It may be
   placed on the company website, Facebook, or another application.

The role invitations are short-lived, HMAC-signed, workspace- and
channel-scoped tokens. Supabase stores only their hashes and lifecycle state;
the recipient-side redemption route will validate and consume the token before
creating membership.

Workspace membership and invitation acceptance are separate from a person’s
initial WhatsApp or Telegram consent.

Chatti uses Supabase's shared Location Service when the Owner shares a location
during onboarding: the derived IANA timezone configures workspace hours, while
the raw pin remains purpose-bound as a workspace-base location. Future customer
request or delivery pins are stored separately with request/delivery purpose
and expiry; they do not become permanent workspace or profile locations.

## 4. Daily availability

Every Admin and Worker must be Checked-In during the workspace’s working day.
Their status is checked first: a Checked-Out member is Checked-In, while a
member already Checked-In remains available without a duplicate check-in.

Only Checked-In Admins and Workers may receive workspace messages. Chatti
automatically Checks-Out them at the configured end of the working day.

## 5. Routed user conversation

1. A User opens the workspace entry link or scans its QR code and submits a
   request, such as “I need brake pads.”
2. Chatti puts the request in a round-robin queue and selects a Checked-In
   Worker who is not already on a chat.
3. Eligible Workers receive the request and an Accept option.
4. When one Worker accepts, Chatti connects that Worker with the User and
   deletes the Accept request from the other eligible Workers’ chats.
5. The Worker and User converse. An Admin may monitor or enter the Worker’s
   conversation for internal guidance; the Admin receives and emits no
   user-facing messages.
6. The Worker closes the chat. The Worker then becomes available for the next
   queued request.

## 6. Conversation controls

Administrators can switch into a named Worker’s conversation, receive a Worker
notification that they have joined, optionally provide internal guidance, and
exit the Worker conversation. Administrators cannot message the User. This
keeps accountability clear: user-facing messages always come from a Worker
role.

Workers can use **Close Chat** to end their assigned conversation. Closing a
chat clears the Worker’s active assignment so Chatti can route the next request
to them when they remain Checked-In.

## 7. Current diagram

The Mermaid sequence diagram is the concise working specification for the
current workflow:

```text
dialog.mer
```

It illustrates onboarding, the initial role transition, daily Check-In logic,
round-robin request assignment, the clean-up of unselected Accept prompts,
Admin monitoring, and Worker chat closure.
