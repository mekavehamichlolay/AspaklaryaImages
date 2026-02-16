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
      if (openResponse) {
        console.log(openResponse);
        openImages.forEach((li) => {
          li.style.display = "none";
        });
      } else {
        mw.notify("אירעה שגיאה בעת עדכון הסטטוס ל'פתוח'", { type: "error" });
      }
    }
    if (blockedNames.length > 0) {
      const blockedResponse = await sendApi(blockedNames, "blocked", api);
      if (blockedResponse) {
        console.log(blockedResponse);
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
   * @return {Promise<string[]>}
   */
  async function sendApi(imageNames, status, api) {
    const done = [];
    for (; imageNames.length; ) {
      const images = imageNames.splice(
        imageNames.length > 50 ? imageNames.length - 50 : 0,
        50,
      );
      try {
        const res = await api.postWithToken("csrf", {
          action: "aspaklaryaimages-manage-status",
          titles: images.join("|"),
          netfree: status,
        });
        const json = await res.json();
        if (json?.["aspaklaryaimages-status"]?.updated) {
          done.push(json?.["aspaklaryaimages-status"]?.updated);
        }
      } catch (error) {
        console.error("Error sending API request:", error);
        return null;
      }
      return done;
    }
  }

  document.getElementById(formId)?.addEventListener("submit", handleSubmit);
})();
