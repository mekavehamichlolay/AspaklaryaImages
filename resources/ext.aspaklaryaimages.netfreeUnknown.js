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
      sendApi(openNames, "open", api);
    }
    if (blockedNames.length > 0) {
      sendApi(blockedNames, "blocked", api);
    }
  }
  /**
   *
   * @param {string[]} imageNames
   * @param {"open"|"blocked"} status
   * @param {mw.Api} api
   */
  function sendApi(imageNames, status, api) {
    for (; imageNames.length; ) {
      const images = imageNames.splice(
        imageNames.length > 50 ? imageNames.length - 50 : 0,
        50,
      );
      api
        .postWithToken("csrf", {
          action: "aspaklaryaimages-manage-status",
          titles: images.join("|"),
          netfree: status,
        })
        .done((data) => {
          if (data?.["aspaklaryaimages-manage-status"]?.updated) {
            for (const title in data["aspaklaryaimages-manage-status"]
              .updated) {
              const li = document.getElementById(`aspaklaryaimages-netfree-options-${title}`)
                ?.closest("li.gallerybox");
                if(li) {
                    li.style.display = "none";
                }
            }
          }
        })
        .fail(() => {
          mw.notify(
            `אירעה שגיאה בעת עדכון הסטטוס ל'${status === "open" ? "פתוח" : "חסום"}'`,
            { type: "error" },
          );
        });
    }
  }

  document.getElementById(formId).addEventListener("submit", handleSubmit);
})();
