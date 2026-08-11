# QA-RC-02: Install/Deployment Dry Run

## Purpose

Prove that the install and deployment documentation is sufficient on its own:
a second person — someone other than the author of that documentation — stands
up a Meridian node from a clean machine using only what the documents say, and
captures evidence of the attempt in the form this script defines. This is the
development process section 20 checklist line "Install/deployment docs tested
by a second person" made runnable, and the evidence form is the requirement
M19.7 documents: the release candidate QA index
([`QA-RC-01`](QA-RC-01-release-candidate-index.md), step 28) attaches this
script's evidence rather than defining its own.

What is under test is the documentation, not the person. A step that cannot be
completed without asking the author is a documentation failure however easily
the author could have answered, because at a real deployment the author is not
standing behind the technician.

## Requirements covered

- Development process section 20, the "Install/deployment docs tested by a
  second person" checklist line.
- Development process section 14, Phase 5 (Release candidate QA), the "install
  docs tested by someone other than the author" check.
- The install/deployment half of the milestone 19 QA gate: a second human can
  follow install/deployment instructions.

The documents under test are [`deploy/README.md`](../../deploy/README.md),
[Deployment](../technician/deployment.md),
[Node setup and pairing](../technician/node-setup-and-pairing.md), and
[`deploy/dns/README.md`](../../deploy/dns/README.md) where the event-network
DNS section is exercised. The technical spec sections those documents
implement (4, 8, 26) are covered by their owning tasks and scripts; this
script covers whether the documents transmit them.

## Environment

- A clean machine: no Meridian repository checkout, no Meridian Docker images,
  containers, or volumes, and no `deploy/docker/.env.deployment` from a
  previous attempt. General-purpose prerequisites the documents assume — Git,
  Docker with Compose, and Node.js with corepack — may be preinstalled;
  whatever is preinstalled is recorded as part of the starting state rather
  than hidden.
- The release candidate build: the repository at the release candidate commit,
  deployed through the committed deployment bundle (`deploy/`), not
  `php artisan serve`.
- The node role the dry run targets is `standalone`, because it exercises the
  full install without requiring a second machine. If a second machine is
  available, the central/on-site pairing walkthrough may be exercised too and
  is recorded the same way, but the checklist line does not require it —
  pairing behavior itself is [`QA-SYNC-01`](QA-SYNC-01-onsite-central-sync.md)'s.

## Personas

- The second person — the one at the keyboard. Anyone who is not an author of
  the documents under test and has not previously deployed Meridian on this
  machine. Familiarity with Docker and a terminal is assumed by the documents
  and therefore allowed; familiarity with Meridian's internals is not
  required, and the less there is, the stronger the evidence.
- The release decider — receives the evidence and attaches it to the release
  candidate record via [`QA-RC-01`](QA-RC-01-release-candidate-index.md). May
  be the same person as an author of the docs; may not be the second person.

The author of the documentation may observe, because watching where a reader
gets stuck is the point. The author may not answer questions, touch the
machine, dictate commands, or fix anything while the run is open. A question
the second person has to ask is recorded as a stuck point against the
document that should have answered it, whether or not anyone answers.

## Setup data

- The repository at the release candidate commit, obtained by the second
  person the way the documents say to obtain it.
- Real values for the secrets the documents say are not Meridian's to mint,
  prepared before the run starts and handed over as values only: a database
  password of the second person's own choosing, and working mail credentials
  (a disposable or test SMTP account is fine). Handing over a value is not
  help; the documents are what must say where each value goes.
- No Meridian seed data. This script deliberately starts before any data
  exists; the node's first data is what first-run setup creates.

## Steps

1. Record the starting state before touching anything: machine and operating
   system, what relevant tooling is preinstalled and its versions (Git,
   Docker, Compose, Node.js/corepack), and confirmation that no Meridian
   checkout, image, container, volume, or environment file exists on the
   machine.
2. Record which documents are being followed, by path, and the commit or
   release candidate version they are being read at.
3. Start a timer. Following [`deploy/README.md`](../../deploy/README.md) and
   [Deployment](../technician/deployment.md) and nothing else, bring up a
   `standalone` node: copy and fill the sample configuration, build the
   images, set the image tag, generate the application key, choose the
   certificate path, start the stack, and confirm it started.
4. Keep a step log as the run goes: for each documented step, record whether
   it behaved as documented, diverged (worked, but not as described — record
   what differed), or stuck (could not proceed without going outside the
   documents). For each stuck point record what the document said, what
   actually happened, and how it was resolved — self-resolved from the
   documents under test, self-resolved from outside them (a web search, prior
   knowledge — record what was consulted), or not resolved. Author
   intervention is not a resolution; if it happens, the run has failed and
   the log records where.
5. If the stack refuses to start over a sample secret, plain-HTTP `APP_URL`,
   or another fail-closed check, follow what the refusal and the documents
   say to do. The refusal is the product working; whether the documents get
   the second person past it unaided is what is being tested.
6. Continue through first-run setup per
   [Node setup and pairing](../technician/node-setup-and-pairing.md): open the
   served node in a browser, complete setup, and reach the God Mode console
   landing screen.
7. Stop the timer. Capture arrival proof: the node answering
   `GET /api/health` through the proxy over HTTPS, and the God Mode landing
   screen after first-run setup.
8. Write the verdict and assemble the evidence record per **Evidence to
   capture**. File a documentation issue for every stuck point and every
   divergence worth fixing, and list the filed issues in the record.

## Expected results

- The node comes up from the documents alone: the stack starts, the node
  serves over HTTPS, first-run setup completes, and the God Mode landing
  screen is reached, with no author intervention at any point.
- Every stuck point, if any, was self-resolved, is recorded with what it took
  to resolve, and has a documentation issue filed.
- The evidence record is complete in the form below and is attached to the
  release candidate record by
  [`QA-RC-01`](QA-RC-01-release-candidate-index.md) step 28.

## Evidence to capture

This section is the M19.7 requirement: the second person's evidence is one
record containing all of the following, and the section 20 line is not
checkable from anything less.

- **Who and when.** The second person's name, the date, the total duration,
  and a one-line attestation that they are not an author of the documents
  followed and had not previously deployed Meridian on this machine.
- **Starting state.** The step 1 record: machine, operating system,
  preinstalled tooling and versions, and the confirmation that nothing
  Meridian-specific pre-existed.
- **Documents followed.** The step 2 record: each document by path, and the
  commit or release candidate version it was read at.
- **The step log.** Every documented step with its outcome — as documented,
  diverged, or stuck — and for each stuck point: what the document said, what
  happened, what resolved it, and what was consulted outside the documents if
  anything.
- **Command transcript.** The commands run and their visible output for the
  build, key generation, stack start, and status/log checks. Secret values
  never appear: the tooling prints variable names rather than values by
  design, and the transcript must be captured the same way — a transcript
  containing a real `APP_KEY`, database password, or mail credential is
  redacted before it enters the record.
- **Arrival proof.** The `GET /api/health` response through the proxy over
  HTTPS, and a screenshot of the God Mode landing screen after first-run
  setup.
- **Verdict.** Pass or fail, in the second person's own words, with the
  failure point named if it failed.
- **Filed issues.** The documentation issues filed from stuck points and
  divergences, by link, or an explicit statement that there were none.

## Failure notes

- Author intervention fails the run. Record where it became necessary — that
  is the finding — fix the documents, and re-run from a clean machine. A
  re-run after documentation fixes is a new record; it does not amend the
  failed one.
- A fail-closed refusal (sample secrets, plain-HTTP `APP_URL`, an unservable
  offline read set) is not a failure of this script — it is technical spec
  26.2 working. It becomes a finding only when the documents do not get the
  second person past it unaided.
- A stuck point self-resolved from outside the documents is a pass with
  findings, not a clean pass: the run completed, but the documents did not
  carry it, and the issue filed for the gap is part of the evidence.
- A failed or incomplete dry run blocks the section 20 line and therefore the
  release candidate; [`QA-RC-01`](QA-RC-01-release-candidate-index.md)
  records the judgement. The line is never checked from a run whose evidence
  record is missing pieces of the form above.
