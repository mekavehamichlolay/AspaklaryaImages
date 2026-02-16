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
          if (data?.["aspaklaryaimages-status"]?.updated) {
            for (const title in data["aspaklaryaimages-status"].updated) {
              const li = document
                .getElementById(`aspaklaryaimages-netfree-options-${title}`)
                ?.closest("li.gallerybox");
              if (li) {
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

  const netfreeAutoButton = document.createElement("button");
  netfreeAutoButton.type = "button";
  netfreeAutoButton.textContent = "זיהוי אוטומטי";
  netfreeAutoButton.classList.add("netfree-auto-button");
  netfreeAutoButton.addEventListener("click", async () => {
    const blockedSelector = 'input[type="radio"][value="blocked"]';
    const openSelector = 'input[type="radio"][value="open"]';

    document.querySelectorAll("li.gallerybox").forEach(async (li) => {
      const img = li.querySelector("img");
      if (!img) return;

      try {
        const src = `${img.src}&~nfopt(getInfoOnly=1)`;
        const response = await fetch(src);
        if (!response.ok) {
          throw new Error(`HTTP error! status: ${response.status}`);
        }
        const text = await response.text();
        const r = JSON.parse(text);
        let selector = null;
        if (r.value === 50) {
          selector = blockedSelector;
          console.log("✅ זוהה חסום");
        } else if (r.value === 100) {
          selector = openSelector;
          console.log("✅ זוהה פתוח");
        } else {
          console.log("⚠️ התמונה בבדיקה");
        }

        if (selector) {
          const ra = li.querySelector(selector);
          if (ra) ra.checked = true;
        }
      } catch (error) {
        console.log("❌ דילוג על תמונה עקב שגיאת רשת/נטפרי:", error.message);
        return; // דילוג על התמונה הנוכחית והמשך לתמונה הבאה
      }
    });
  });
  document.getElementById(formId).appendChild(netfreeAutoButton);
})();
