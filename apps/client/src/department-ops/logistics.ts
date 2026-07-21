import type {
  EquipmentReturnCondition,
  LogisticsDeskModel,
  LogisticsSearchHit,
  LogisticsShiftCard,
  LogisticsStaffWorkspace,
  ShiftOption,
} from "@/department-ops/types";

/** Early/late buffer around scheduled start/end for “current” desk listing. */
export const CURRENT_SHIFT_WINDOW_MINUTES = 15;

const CURRENT_SHIFT_WINDOW_MS = CURRENT_SHIFT_WINDOW_MINUTES * 60 * 1000;

function normalize(value: string): string {
  return value.trim().toLowerCase();
}

/**
 * True when asOf is inside [startsAt − 15 minutes, endsAt + 15 minutes].
 * Covers shifts in progress, ones that ended up to 15 minutes ago, and ones
 * that start up to 15 minutes from now.
 */
export function isShiftCurrentlyGoing(
  shift: Pick<ShiftOption, "startsAt" | "endsAt">,
  asOf: string,
): boolean {
  const now = Date.parse(asOf);
  const startsAt = Date.parse(shift.startsAt);
  const endsAt = Date.parse(shift.endsAt);

  if (
    Number.isNaN(now) ||
    Number.isNaN(startsAt) ||
    Number.isNaN(endsAt) ||
    endsAt < startsAt
  ) {
    return false;
  }

  return (
    now >= startsAt - CURRENT_SHIFT_WINDOW_MS &&
    now <= endsAt + CURRENT_SHIFT_WINDOW_MS
  );
}

export function currentLogisticsShifts(
  desk: LogisticsDeskModel,
  asOf: string = desk.context.asOf,
): readonly ShiftOption[] {
  return desk.searchableShifts
    .filter((shift) => isShiftCurrentlyGoing(shift, asOf))
    .slice()
    .sort((left, right) => left.startsAt.localeCompare(right.startsAt));
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
    selectedSearchContext: null,
  };
}

export function selectLogisticsHit(
  desk: LogisticsDeskModel,
  hit: LogisticsSearchHit,
): LogisticsDeskModel {
  if (hit.kind === "staff") {
    return selectLogisticsStaff(desk, hit.id);
  }

  if (hit.kind === "equipment") {
    const holder = desk.searchableEquipment.find(
      (item) => item.equipmentItemId === hit.id,
    )?.holderName;
    const staff = desk.searchableStaff.find(
      (member) => member.displayName === holder,
    );

    return {
      ...desk,
      selectedStaffId: staff?.staffId ?? null,
      selectedSearchContext: {
        id: hit.id,
        kind: hit.kind,
        label: hit.label,
        detail: hit.detail,
        relatedStaffIds: staff ? [staff.staffId] : [],
        emptyReason: staff
          ? null
          : "This item is available in the department cache and is not checked out to a staff member.",
      },
    };
  }

  const relatedStaffIds = Object.values(desk.staffWorkspaces)
    .filter((workspace) =>
      workspace.shiftCards.some((card) => card.shiftId === hit.id),
    )
    .map((workspace) => workspace.staffId);

  return {
    ...desk,
    selectedStaffId:
      relatedStaffIds.length === 1 ? (relatedStaffIds[0] ?? null) : null,
    selectedSearchContext: {
      id: hit.id,
      kind: hit.kind,
      label: hit.label,
      detail: hit.detail,
      relatedStaffIds,
      emptyReason:
        relatedStaffIds.length === 0
          ? "No staff workspace in the local department cache references this shift yet."
          : null,
    },
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

export function logisticsShiftSections(workspace: LogisticsStaffWorkspace): {
  readonly active: readonly LogisticsShiftCard[];
  readonly upcoming: readonly LogisticsShiftCard[];
  readonly outgoing: readonly LogisticsShiftCard[];
} {
  return {
    active: workspace.shiftCards.filter(
      (card) =>
        card.lifecycle === "active" || card.attendanceState === "checked_in",
    ),
    upcoming: workspace.shiftCards.filter(
      (card) =>
        card.lifecycle === "upcoming" && card.attendanceState !== "checked_in",
    ),
    outgoing: workspace.shiftCards.filter(
      (card) =>
        card.lifecycle === "completed" ||
        card.attendanceState === "checked_out",
    ),
  };
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
    selectedSearchContext: null,
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
    selectedSearchContext: null,
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
    searchableEquipment: desk.searchableEquipment.map((item) =>
      equipmentItemIds.includes(item.equipmentItemId)
        ? { ...item, status: "checked_out", holderName: workspace.displayName }
        : item,
    ),
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
    selectedSearchContext: null,
  };
}

export function checkOutLogisticsEquipment(
  desk: LogisticsDeskModel,
  staffId: string,
  checkedOutAt: string,
  equipmentItemIds: readonly string[],
): LogisticsDeskModel {
  const workspace = desk.staffWorkspaces[staffId];
  if (workspace === undefined) {
    throw new Error("Staff member is not available in this department.");
  }

  if (workspace.presenceState !== "on_site") {
    throw new Error("Staff must be on-site before equipment checkout.");
  }

  if (equipmentItemIds.length === 0) {
    throw new Error("Choose at least one available equipment item.");
  }

  const issued = workspace.availableEquipment.filter((item) =>
    equipmentItemIds.includes(item.equipmentItemId),
  );
  if (issued.length !== equipmentItemIds.length) {
    throw new Error("Equipment is not available for checkout.");
  }

  return {
    ...desk,
    searchableEquipment: desk.searchableEquipment.map((item) =>
      equipmentItemIds.includes(item.equipmentItemId)
        ? { ...item, status: "checked_out", holderName: workspace.displayName }
        : item,
    ),
    staffWorkspaces: {
      ...desk.staffWorkspaces,
      [staffId]: {
        ...workspace,
        canGoOffSite: false,
        offSiteBlockedReason:
          "Equipment must be returned or marked missing/damaged before going off-site.",
        availableEquipment: workspace.availableEquipment.filter(
          (item) => !equipmentItemIds.includes(item.equipmentItemId),
        ),
        openEquipment: [
          ...workspace.openEquipment,
          ...issued.map((item) => ({
            ...item,
            checkoutId: `local-checkout-${item.equipmentItemId}-${staffId}`,
            status: "checked_out" as const,
            checkedOutAt,
          })),
        ],
      },
    },
    selectedStaffId: staffId,
    selectedSearchContext: null,
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
    selectedSearchContext: null,
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
    searchableEquipment: desk.searchableEquipment.map((item) =>
      item.equipmentItemId === openItem.equipmentItemId
        ? {
            ...item,
            status: condition === "returned" ? "available" : condition,
            holderName: null,
          }
        : item,
    ),
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
    selectedSearchContext: null,
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
    selectedSearchContext: null,
  };
}
