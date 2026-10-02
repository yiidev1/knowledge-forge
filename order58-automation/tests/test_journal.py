"""The durable run journal, and the states that refuse a second order.

The properties worth guarding here are the awkward ones: that a crashed run blocks, that a run which
touched the server can never be filed as one that did not, and that nothing clears a blocking state
on its own.
"""

from __future__ import annotations

import json
import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from order58 import journal as J  # noqa: E402

SEGMENT = "16655531-15163932150"


class _TempRuns(unittest.TestCase):
    def setUp(self) -> None:
        self._tmp = tempfile.TemporaryDirectory()
        self.runs = Path(self._tmp.name) / "runs"

    def tearDown(self) -> None:
        self._tmp.cleanup()

    def _open(self, **kwargs):
        return J.open_run(
            self.runs, SEGMENT, gate="create", product="Spring Roll", qty=1, **kwargs
        )


class OpeningARun(_TempRuns):
    def test_creates_an_owner_only_file_with_in_progress(self) -> None:
        journal = self._open()

        self.assertTrue(journal.path.exists())
        self.assertEqual(journal.status, J.IN_PROGRESS)
        self.assertEqual(oct(journal.path.stat().st_mode & 0o777), "0o600")

        on_disk = json.loads(journal.path.read_text())
        self.assertEqual(on_disk["segment"], SEGMENT)
        self.assertEqual(on_disk["status"], J.IN_PROGRESS)

    def test_a_second_run_is_blocked_by_the_first(self) -> None:
        """This is the whole point: a live or crashed run refuses the next creation."""
        self._open()

        with self.assertRaises(J.RunBlocked) as caught:
            self._open()

        self.assertIn("unresolved", str(caught.exception))

    def test_a_different_source_order_is_unaffected(self) -> None:
        self._open()

        other = J.open_run(
            self.runs, "11112222-33334444", gate="create", product="X", qty=1
        )
        self.assertEqual(other.status, J.IN_PROGRESS)

    def test_the_block_names_the_order_so_it_can_be_found(self) -> None:
        journal = self._open()
        journal.record_ids("16630999", "6959001")
        journal.close(J.UNKNOWN, detail="submission outcome never established")

        with self.assertRaises(J.RunBlocked) as caught:
            self._open()

        self.assertIn("16630999", str(caught.exception))


class BlockingStates(_TempRuns):
    def test_each_blocking_state_refuses_a_new_run(self) -> None:
        for status in sorted(J.BLOCKING - {J.IN_PROGRESS}):
            with self.subTest(status=status):
                self.setUp()
                journal = self._open()
                journal.close(status, detail=f"test: {status}")

                with self.assertRaises(J.RunBlocked):
                    self._open()

    def test_completed_does_not_block(self) -> None:
        journal = self._open()
        journal.close(J.COMPLETED, detail="confirmed")

        self.assertEqual(self._open().status, J.IN_PROGRESS)

    def test_aborted_before_any_write_does_not_block(self) -> None:
        journal = self._open()
        journal.close(J.ABORTED_NO_WRITE, detail="stopped at preflight")

        self.assertEqual(self._open().status, J.IN_PROGRESS)

    def test_validation_failed_blocks(self) -> None:
        """Asked for explicitly: a rejected submission must not free the source automatically."""
        journal = self._open()
        journal.close(J.VALIDATION_FAILED, detail="the application rejected it")

        self.assertIn(J.VALIDATION_FAILED, J.BLOCKING)

        with self.assertRaises(J.RunBlocked):
            self._open()

    def test_an_unreadable_journal_blocks(self) -> None:
        """A corrupt record of a run that may have written is not a reason to carry on."""
        self.runs.mkdir(parents=True, exist_ok=True)
        (self.runs / "run-broken.json").write_text("{not json")

        with self.assertRaises(J.RunBlocked):
            self._open()


class WriteAttemptsCannotBeHidden(_TempRuns):
    def test_aborted_no_write_is_reclassified_once_a_write_was_attempted(self) -> None:
        """The guarantee: a run that touched the server is never filed as one that did not."""
        journal = self._open()
        journal.mark_write_attempted("continue (create order)")

        journal.close(J.ABORTED_NO_WRITE, detail="product not found")

        self.assertEqual(journal.status, J.ABORTED_AFTER_WRITE)
        self.assertIn("reclassified", journal.data["detail"])
        self.assertIn(journal.status, J.BLOCKING)

    def test_product_not_found_after_creation_blocks(self) -> None:
        """The exact scenario called out: creation succeeded, the product did not exist."""
        journal = self._open()
        journal.mark_write_attempted("continue (create order)")
        journal.record_ids("16630999", "6959001")
        journal.close(J.ABORTED_NO_WRITE, detail="no exact product match")

        self.assertEqual(journal.status, J.ABORTED_AFTER_WRITE)

        with self.assertRaises(J.RunBlocked):
            self._open()

    def test_write_attempts_are_recorded_durably_in_order(self) -> None:
        journal = self._open()
        journal.mark_write_attempted("continue (create order)")
        journal.mark_write_attempted("add to cart")

        on_disk = json.loads(journal.path.read_text())
        self.assertEqual(
            [w["what"] for w in on_disk["writes_attempted"]],
            ["continue (create order)", "add to cart"],
        )

    def test_close_refuses_a_state_that_is_not_terminal(self) -> None:
        journal = self._open()

        with self.assertRaises(ValueError):
            journal.close(J.RESOLVED)

        with self.assertRaises(ValueError):
            journal.close("whatever")


class IdentifiersSurvive(_TempRuns):
    def test_ids_are_on_disk_immediately(self) -> None:
        """Without these, an ambiguous run leaves an order nobody can name."""
        journal = self._open()
        journal.record_ids("16630999", "6959001")

        on_disk = json.loads(journal.path.read_text())
        self.assertEqual(on_disk["order_id"], "16630999")
        self.assertEqual(on_disk["customer_id"], "6959001")

    def test_amounts_and_charges_are_recorded(self) -> None:
        journal = self._open()
        journal.record(subtotal="$1.85", tax="$0.19", total="$2.04",
                       charges=["subtotal=1.85", "tax=0.19"])

        on_disk = json.loads(journal.path.read_text())
        self.assertEqual(on_disk["total"], "$2.04")
        self.assertIn("tax=0.19", on_disk["charges"])


class Resuming(_TempRuns):
    def test_a_prepared_run_is_resumable(self) -> None:
        journal = self._open()
        journal.record_ids("16630999", "6959001")
        journal.close(J.PREPARED, detail="ready for payment")

        found = J.resumable(self.runs, SEGMENT)
        self.assertEqual(len(found), 1)

        reopened = J.reopen(found[0][0])
        self.assertEqual(reopened.status, J.IN_PROGRESS)
        self.assertEqual(reopened.data["order_id"], "16630999")

    def test_a_resumed_run_counts_as_having_written(self) -> None:
        """The order it is resuming already exists, so a later abort must not say otherwise."""
        journal = self._open()
        journal.record_ids("16630999", "6959001")
        journal.close(J.PREPARED)

        reopened = J.reopen(J.resumable(self.runs, SEGMENT)[0][0])
        self.assertTrue(reopened.wrote)

        reopened.close(J.ABORTED_NO_WRITE, detail="stopped")
        self.assertEqual(reopened.status, J.ABORTED_AFTER_WRITE)

    def test_an_unknown_run_is_never_resumable(self) -> None:
        journal = self._open()
        journal.close(J.UNKNOWN, detail="never established")

        self.assertEqual(J.resumable(self.runs, SEGMENT), [])

        with self.assertRaises(J.NoResumableRun):
            J.reopen(journal.path)

    def test_a_completed_run_is_not_resumable(self) -> None:
        journal = self._open()
        journal.close(J.COMPLETED)

        with self.assertRaises(J.NoResumableRun):
            J.reopen(journal.path)


class Resolution(_TempRuns):
    def _blocked(self):
        journal = self._open()
        journal.record_ids("16630999", "6959001")
        journal.close(J.UNKNOWN, detail="outcome never established")

        return journal

    def test_resolving_records_reason_and_evidence_and_unblocks(self) -> None:
        journal = self._blocked()

        J.resolve(
            journal.path,
            reason="Confirmed by hand that no order was placed.",
            evidence="EC2 JSON absent and the admin order list shows no new row.",
        )

        data = json.loads(journal.path.read_text())
        self.assertEqual(data["status"], J.RESOLVED)
        self.assertEqual(data["resolved_from"], J.UNKNOWN)
        self.assertIn("EC2 JSON", data["resolution_evidence"])

        self.assertEqual(self._open().status, J.IN_PROGRESS)

    def test_a_thin_reason_is_refused(self) -> None:
        journal = self._blocked()

        with self.assertRaises(ValueError):
            J.resolve(journal.path, reason="ok", evidence="checked the EC2 JSON thoroughly")

    def test_missing_evidence_is_refused(self) -> None:
        """"Resolved" with nothing stated is indistinguishable from deleting the file."""
        journal = self._blocked()

        with self.assertRaises(ValueError):
            J.resolve(journal.path, reason="I looked at it and it seems fine", evidence="")

    def test_resolving_a_non_blocking_run_is_refused(self) -> None:
        journal = self._open()
        journal.close(J.COMPLETED)

        with self.assertRaises(ValueError):
            J.resolve(journal.path, reason="nothing to do here really",
                      evidence="it was already completed and confirmed")

    def test_nothing_expires_on_its_own(self) -> None:
        """No TTL, no sweeper. An old blocking journal blocks exactly as hard as a new one."""
        journal = self._blocked()
        data = json.loads(journal.path.read_text())
        data["opened_at"] = "1999-01-01T00:00:00+0000"
        journal.path.write_text(json.dumps(data))

        with self.assertRaises(J.RunBlocked):
            self._open()


class MarkerFilename(unittest.TestCase):
    def test_run_and_phase_b_compute_the_same_marker_path(self) -> None:
        """If they disagreed, each could submit an order the other already had."""
        import create_demo_order as cli

        state = Path("/var/www/html/knowledge-forge/order58-automation/state")
        self.assertEqual(
            J.attempt_marker_path(state, "16630311"),
            cli._attempt_marker("16630311"),
        )

    def test_the_existing_phase_b_marker_is_still_recognised(self) -> None:
        """Order 16630311 was really submitted. Its marker must keep blocking."""
        import create_demo_order as cli

        marker = cli._attempt_marker("16630311")
        self.assertEqual(marker.name, "phase-b-attempt-16630311.json")


if __name__ == "__main__":
    unittest.main(verbosity=2)
