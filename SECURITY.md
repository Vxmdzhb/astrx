# Security policy

AstrX is meant to run as a hidden service, where a vulnerability can expose
the people who use it. Please report one privately.

## Reporting

Use GitHub's private vulnerability reporting: the **Security** tab of the
repository → **Report a vulnerability**. Do not open a public issue, pull
request or discussion about it.

Include:
- the commit;
- the affected repository (the site, a package or module, or an engine);
- the configuration it needs, such as enabled modules or cookieless sessions;
- the steps or a proof of concept;
- what an attacker gains.

Leave real users' data out of the report.

## What to expect

You will get an answer in the advisory thread. A fix is developed there and
released with the advisory, crediting you unless you ask otherwise.

## Scope

In scope:
- this repository (`src/`, `public/`, `tools/`, the configuration defaults,
  the Docker files);
- the PHP packages: framework, library, cms and every module-*;
- the Rust engines and astrx-suite.

Out of scope are weaknesses that need an already-compromised administrator
account or server. The project has not been independently audited (see the
README).
