import type {
  EquipmentReturnCondition,
  LogisticsDeskModel,
  LogisticsSearchHit,
  LogisticsStaffWorkspace,
} from "@/department-ops/types";

function normalize(value: string): string {
  return value.trim().toLowerCase();
}

export function searchLogisticsDesk(
  desk: LogisticsDeskModel,
  query: string,
): readonly LogisticsSearchHit[] {
  const needle = normalize(query);
  if (needle.length === 0) {
    return [];
  }

  const staffHits = desk.searchableStaff
    .filter((staff) => {
      const haystack = normalize(
        `${staff.displayName} ${staff.handle ?? ""} ${staff.teamLabel}`,
      );
      return haystack.includes(needle);
    })
    .map(
      (staff) =>
        ({
          id: staff.staffId,
          kind: "staff",
          label: staff.displayName,
          detail: `${staff.teamLabel} · ${staff.presenceState === "on_site" ? "On-site" : "Off-site"}`,
        }) satisfies LogisticsSearchHit,
    );

  const equipmentHits = desk.searchableEquipment
    .filter((item) => {
      const haystack = normalize(
        `${item.name} ${item.assetTag ?? ""} ${item.holderName ?? ""}`,
      );
      return haystack.includes(needle);
    })
    .map(
      (item) =>
        ({
          id: item.equipmentItemId,
          kind: "equipment",
          label: item.assetTag ? `${item.name} (${item.assetTag})` : item.name,
          detail: item.holderName
            ? `Checked out to ${item.holderName}`
            : item.status,
        }) satisfies LogisticsSearchHit,
    );

  const shiftHits = desk.searchableShifts
    .filter((shift) => {
      const haystack = normalize(`${shift.title} ${shift.teamLabel}`);
      return haystack.includes(needle);
    })
    .map(
      (shift) =>
        ({
          id: shift.shiftId,
          kind: "shift",
          label: shift.title,
          detail: `${shift.teamLabel} · ${shift.lifecycle}`,
        }) satisfies LogisticsSearchHit,
    );

  return [...staffHits, ...equipmentHits, ...shiftHits];
}

export function selectLogisticsStaff(
  desk: LogisticsDeskModel,
  staffId: string,
): LogisticsDeskModel {
  if (!(staffId in desk.staffWorkspaces)) {
    throw new Error("Staff member is not available in this department.");
  }

  return {
    ...desk,
    selectedStaffId: staffId,
  };
}

export function selectedLogisticsWorkspace(
  desk: LogisticsDeskModel,
): LogisticsStaffWorkspace | null {
  if (desk.selectedStaffId === null) {
    return null;
  }

  return desk.staffWorkspaces[desk.selectedStaffId] ?? null;
}

export function markLogisticsStaffOnSite(
  desk: LogisticsDeskModel,
  staffId: string,
): LogisticsDeskModel {
  const workspace = desk.staffWorkspaces[staffId];
  if (workspace === undefined) {
    throw new Error("Staff member is not available in this department.");
  }

  return {
    ...desk,
    searchableStaff: desk.searchableStaff.map((staff) =>
      staff.staffId === staffId
        ? { ...staff, presenceState: "on_site" }
        : staff,
    ),
    staffWorkspaces: {
      ...desk.staffWorkspaces,
      [staffId]: {
        ...workspace,
        presenceState: "on_site",
        shiftCards: workspace.shiftCards.map((card) =>
          card.assignmentId !== null && card.attendanceState === "scheduled"
            ? { ...card, canCheckIn: true }
            : card,
        ),
      },
    },
    selectedStaffId: staffId,
  };
}

export function markLogisticsStaffOffSite(
  desk: LogisticsDeskModel,
  staffId: string,
): LogisticsDeskModel {
  const workspace = desk.staffWorkspaces[staffId];
  if (workspace === undefined) {
    throw new Error("Staff member is not available in this department.");
  }

  if (!workspace.canGoOffSite) {
    throw new Error(
      workspace.offSiteBlockedReason ??
        "Staff cannot go off-site in the current state.",
    );
  }

  return {
    ...desk,
    searchableStaff: desk.searchableStaff.map((staff) =>
      staff.staffId === staffId
        ? { ...staff, presenceState: "off_site" }
        : staff,
    ),
    staffWorkspaces: {
      ...desk.staffWorkspaces,
      [staffId]: {
        ...workspace,
        presenceState: "off_site",
        shiftCards: workspace.shiftCards.map((card) => ({
          ...card,
          canCheckIn: false,
        })),
      },
    },
    selectedStaffId: staffId,
  };
}

export function checkInLogisticsStaff(
  desk: LogisticsDeskModel,
  staffId: string,
  shiftId: string,
  checkedInAt: string,
  equipmentItemIds: readonly string[] = [],
): LogisticsDeskModel {
  const workspace = desk.staffWorkspaces[staffId];
  if (workspace === undefined) {
    throw new Error("Staff member is not available in this department.");
  }

  if (workspace.presenceState !== "on_site") {
    throw new Error("Staff must be on-site before check-in.");
  }

  const shiftCard = workspace.shiftCards.find((card) => card.shiftId === shiftId);
  if (shiftCard === undefined || !shiftCard.canCheckIn) {
    throw new Error("Shift is not available for check-in.");
  }

  const issued = workspace.availableEquipment.filter((item) =>
    equipmentItemIds.includes(item.equipmentItemId),
  );

  return {
    ...desk,
    staffWorkspaces: {
      ...desk.staffWorkspaces,
      [staffId]: {
        ...workspace,
        canGoOffSite: false,
        offSiteBlockedReason:
          "Staff must be checked out of their shift before going off-site.",
        shiftCards: workspace.shiftCards.map((card) =>
          card.shiftId === shiftId
            ? {
                ...card,
                attendanceState: "checked_in",
                canCheckIn: false,
                canCheckOut: true,
              }
            : card,
        ),
        availableEquipment: workspace.availableEquipment.filter(
          (item) => !equipmentItemIds.includes(item.equipmentItemId),
        ),
        openEquipment: [
          ...workspace.openEquipment,
          ...issued.map((item) => ({
            ...item,
            checkoutId: `local-checkout-${item.equipmentItemId}-${staffId}`,
            status: "checked_out" as const,
            checkedOutAt: checkedInAt,
          })),
        ],
      },
    },
    selectedStaffId: staffId,
  };
}

export function checkOutLogisticsStaff(
  desk: LogisticsDeskModel,
  staffId: string,
  shiftId: string,
): LogisticsDeskModel {
  const workspace = desk.staffWorkspaces[staffId];
  if (workspace === undefined) {
    throw new Error("Staff member is not available in this department.");
  }

  const shiftCard = workspace.shiftCards.find((card) => card.shiftId === shiftId);
  if (shiftCard === undefined || !shiftCard.canCheckOut) {
    throw new Error("Shift is not available for check-out.");
  }

  const hasOpenEquipment = workspace.openEquipment.length > 0;

  return {
    ...desk,
    staffWorkspaces: {
      ...desk.staffWorkspaces,
      [staffId]: {
        ...workspace,
        canGoOffSite: !hasOpenEquipment,
        offSiteBlockedReason: hasOpenEquipment
          ? "Equipment must be returned or marked missing/damaged before going off-site."
          : null,
        shiftCards: workspace.shiftCards.map((card) =>
          card.shiftId === shiftId
            ? {
                ...card,
                attendanceState: "checked_out",
                canCheckIn: false,
                canCheckOut: false,
              }
            : card,
        ),
      },
    },
    selectedStaffId: staffId,
  };
}

export function returnLogisticsEquipment(
  desk: LogisticsDeskModel,
  staffId: string,
  checkoutId: string,
  condition: EquipmentReturnCondition,
): LogisticsDeskModel {
  const workspace = desk.staffWorkspaces[staffId];
  if (workspace === undefined) {
    throw new Error("Staff member is not available in this department.");
  }

  const openItem = workspace.openEquipment.find(
    (item) => item.checkoutId === checkoutId,
  );
  if (openItem === undefined) {
    throw new Error("Checked-out equipment is not available for return.");
  }

  const remainingOpen = workspace.openEquipment.filter(
    (item) => item.checkoutId !== checkoutId,
  );
  const stillCheckedIn = workspace.shiftCards.some(
    (card) => card.attendanceState === "checked_in",
  );

  return {
    ...desk,
    staffWorkspaces: {
      ...desk.staffWorkspaces,
      [staffId]: {
        ...workspace,
        openEquipment: remainingOpen,
        availableEquipment:
          condition === "returned"
            ? [
                ...workspace.availableEquipment,
                {
                  ...openItem,
                  checkoutId: null,
                  status: "available",
                  checkedOutAt: null,
                },
              ]
            : workspace.availableEquipment,
        canGoOffSite: !stillCheckedIn && remainingOpen.length === 0,
        offSiteBlockedReason:
          stillCheckedIn || remainingOpen.length > 0
            ? workspace.offSiteBlockedReason
            : null,
      },
    },
    selectedStaffId: staffId,
  };
}

export function addLogisticsStaffToShift(
  desk: LogisticsDeskModel,
  staffId: string,
  shiftId: string,
  assignmentId: string,
): LogisticsDeskModel {
  const workspace = desk.staffWorkspaces[staffId];
  if (workspace === undefined) {
    throw new Error("Staff member is not available in this department.");
  }

  if (workspace.presenceState !== "on_site") {
    throw new Error("Staff must be on-site before being added to a shift.");
  }

  const shiftCard = workspace.shiftCards.find((card) => card.shiftId === shiftId);
  if (shiftCard === undefined || !shiftCard.canAddToShift) {
    throw new Error("Shift is not available for unscheduled addition.");
  }

  return {
    ...desk,
    staffWorkspaces: {
      ...desk.staffWorkspaces,
      [staffId]: {
        ...workspace,
        shiftCards: workspace.shiftCards.map((card) =>
          card.shiftId === shiftId
            ? {
                ...card,
                assignmentId,
                attendanceState: "scheduled",
                canAddToShift: false,
                canCheckIn: true,
              }
            : card,
        ),
      },
    },
    selectedStaffId: staffId,
  };
}
