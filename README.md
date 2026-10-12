# vivutio/ticketing-module

**Ticketing for vivutio.** Tickets a travel agency buys from airlines,
ferries, coaches and railways for travellers: recorded by hand as the carrier
made them, held to the carrier's time limit or issued with a ticket number a
traveller, then changed, refunded or voided, every step kept on the ticket.

## Contents

- [Install](#install)
- [How it works](#how-it-works)
- [Who may do what](#who-may-do-what)
- [Development](#development)
- [Licence](#licence)

## Install

In a vivutio installation:

```bash
composer require vivutio/ticketing-module
php bin/console doctrine:migrations:migrate
```

Its recipe registers the bundle and mounts its pages with
`config/routes/ticketing.yaml`, a file the installation owns.

## How it works

The agency keeps its **carriers**: an airline with its two-letter code and
three-digit ticket prefix, a ferry, a coach or a railway, and how the agency
buys from each. No list is shipped, so none goes stale.

A **ticket** is made in the carrier's or a booking system's own pages and
recorded here: the carrier and its reference, the class and baggage, up to six
legs (from, to, the day, leaving and arriving), and up to nine travellers, each
with a fare, a ticket number once issued and a seat. A ticket is of its
carrier's kind. It is referred to as "TK-0001".

It is recorded **held**, to the carrier's time limit, or **issued**. A held
ticket is issued with a ticket number for each traveller (an airline's start
with its prefix) or let go. An issued ticket is **changed** as the carrier
changed it, with what the carrier charged; **refunded**, keeping what the
carrier returns and what the agency keeps; or **voided**, the carrier's
same-day cancel, only on the day it was issued in the organization's time
zone.

It is **billed** to the traveller who pays, or to a client among the
partners: a company or a tour operator traded with now. The money shown is the
fares, the agency's fee for each traveller, the taxes in the fares and what
the carrier charged for changes.

On the organization's **dashboard**, for whoever holds `tickets.read`, it
puts three figures in the band (held, to issue; travelling this week; issued
this month) and two cards: the held tickets the soonest let go first, and the
issued ones with a leg leaving in the next seven days. Days and hours are the
organization's time zone.

The module names no other module; it reads partners through the core's
`PartnerDirectoryInterface` and keeps only a partner's id, says what tickets
are owed through `ChargeSourceInterface`, and gives its dashboard through
`DashboardSourceInterface`.

## Who may do what

| Pair | Who |
|---|---|
| `tickets.read` | The register, a ticket's page and the carriers |
| `tickets.record` | Recording a new ticket |
| `tickets.manage` | Issuing, letting go, changing, refunding and voiding; adding carriers |

Each is a module pair, held where the person's department allows it too. The
suite extends the core's authority test base; `tests/authority-table.md` is
the reviewed table.

## Development

```bash
composer update
composer check
```

## Licence

AGPL-3.0-or-later. See [LICENSE](LICENSE).
