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

## 2. Workspace roles and hierarchy

| Role | Responsibility | User-facing chat |
| --- | --- | --- |
| Owner | Creates the workspace and owns its administration. | Only while also holding the Worker role. |
| Admin | Administers invitations, worker availability, and worker-conversation monitoring. | No. |
| Worker | Receives routed requests and carries the user conversation. | Yes. |
| User | Enters through a workspace QR code, HTML code, or link to request help. | With a Worker only. |

The role hierarchy is **Owner → Admin → Worker → User**.

The person who creates a workspace starts with Owner, Admin, and Worker roles.
For example, Petrus creates the Mechanical Parts Company workspace. If Petrus
invites Jacques as an Admin, Petrus becomes Owner-only and Jacques joins with
Admin and User roles, but without the Worker role. Jacques can then invite
Jesse as a Worker. A Worker is the only role permitted to hold a user-facing
conversation.

## 3. Workspace onboarding

1. The founding Owner creates and names the workspace.
2. The Owner sets the workspace time zone and working hours.
3. The Owner may send an Admin invitation link containing the workspace key.
   The recipient opens the link and replies to accept, joining that workspace.
4. An Admin may send a Worker invitation link containing the workspace key.
   The recipient opens the link and replies to accept, joining that workspace.
5. A Worker publishes a workspace entry link, QR code, or HTML code. It may be
   placed on the company website, Facebook, or another application.

Workspace membership and invitation acceptance are separate from a person’s
initial WhatsApp or Telegram consent.

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
