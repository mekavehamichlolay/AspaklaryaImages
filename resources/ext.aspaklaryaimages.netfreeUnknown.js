(() => {
  const formId = "aspaklaryaimages-netfree-form";
  /**
   *
   * @param {SubmitEvent} e
   */
  async function handleSubmit(e) {
    e.preventDefault();
    e.stopPropagation();
    const openNames = [];
    const openImages = [];
    document
      .querySelectorAll(
        `input[form="${formId}"][type="radio"][value="open"]:checked`,
      )
      .forEach((input) => {
        openImages.push(input.closest("li"));
        openNames.push(input.name);
      });
    const blockedNames = [];
    const blockedImages = [];
    document
      .querySelectorAll(
        `input[form="${formId}"][type="radio"][value="blocked"]:checked`,
      )
      .forEach((input) => {
        blockedImages.push(input.closest("li"));
        blockedNames.push(input.name);
      });
    const api = new mw.Api();
    if (openNames.length > 0) {
      const openResponse = await sendApi(openNames, "open", api);
      if (openResponse?.update) {
        openImages.forEach((li) => {
          li.style.display = "none";
        });
      } else {
        mw.notify("אירעה שגיאה בעת עדכון הסטטוס ל'פתוח'", { type: "error" });
      }
    }
    if (blockedNames.length > 0) {
      const blockedResponse = await sendApi(blockedNames, "blocked", api);
      if (blockedResponse?.update) {
        blockedImages.forEach((li) => {
          li.style.display = "none";
        });
      } else {
        mw.notify("אירעה שגיאה בעת עדכון הסטטוס ל'חסום'", { type: "error" });
      }
    }
  }
  /**
   *
   * @param {string[]} imageNames
   * @param {"open"|"blocked"} status
   * @param {mw.Api} api
   */
  async function sendApi(imageNames, status, api) {
    try {
      return await api.postWithToken("csrf", {
        action: "aspaklaryaimages-manage-status",
        titles: imageNames.join("|"),
        status: status,
      });
    } catch (error) {
      console.error("Error sending API request:", error);
      return null;
    }
  }

  document.getElementById(formId)?.addEventListener("submit", handleSubmit);
})();
