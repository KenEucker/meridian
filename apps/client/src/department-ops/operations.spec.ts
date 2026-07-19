import { describe, expect, it } from "vitest";

import {
  LOCAL_CAPABILITIES,
  LOCAL_OPERATIONS_CENTER,
} from "@/department-ops/fixtures";
import {
  assignOperationsDeployment,
  availableOperationsModules,
  composeOperationsModules,
  deploymentName,
} from "@/department-ops/operations";

describe("operations center model", () => {
  it("composes modules from existing capabilities without shell grants", () => {
    const modules = composeOperationsModules(LOCAL_CAPABILITIES, 1);
    const withoutIc = composeOperationsModules(
      { ...LOCAL_CAPABILITIES, hasIncidentCommand: false },
      1,
    );

    expect(modules.find((module) => module.id === "deployments")?.available).toBe(
      true,
    );
    expect(modules.find((module) => module.id === "incidents")?.available).toBe(
      false,
    );
    expect(modules.find((module) => module.id === "equipment")?.available).toBe(
      true,
    );
    expect(
      withoutIc.find((module) => module.id === "incidents")?.unavailableReason,
    ).toContain("Incident Command");
  });

  it("moves current deployments for operations capability", () => {
    const updated = assignOperationsDeployment(
      LOCAL_OPERATIONS_CENTER,
      "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa2",
      "deployment-hq-runner",
    );

    expect(
      deploymentName(
        updated,
        updated.deploymentRows.find(
          (row) => row.assignmentId === "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa2",
        )?.currentDeploymentId ?? null,
      ),
    ).toBe("HQ Runner");
    expect(
      availableOperationsModules(updated).map((module) => module.id),
    ).toContain("deployments");
  });
});
