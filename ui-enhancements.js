document.addEventListener("DOMContentLoaded", () => {
  const reduceMotion = window.matchMedia(
    "(prefers-reduced-motion: reduce)",
  ).matches;

  document.documentElement.classList.add("ui-enhanced");

  const progress = document.createElement("div");
  progress.id = "ui-scroll-progress";
  progress.setAttribute("aria-hidden", "true");
  document.body.prepend(progress);

  const updateProgress = () => {
    const maxScroll =
      document.documentElement.scrollHeight - window.innerHeight;
    const ratio = maxScroll > 0 ? window.scrollY / maxScroll : 0;
    progress.style.width = `${Math.min(100, Math.max(0, ratio * 100))}%`;
  };

  updateProgress();
  window.addEventListener("scroll", updateProgress, { passive: true });
  window.addEventListener("resize", updateProgress);

  const currentPage =
    window.location.pathname.split("/").pop() || "dashboard.php";
  document
    .querySelectorAll(".nav-links a, .menu a, .topbar a")
    .forEach((link) => {
      const targetPage = (link.getAttribute("href") || "")
        .split("?")[0]
        .split("/")
        .pop();
      if (targetPage === currentPage) {
        link.classList.add("ui-active");
        link.setAttribute("aria-current", "page");
      }
    });

  const revealSelector = [
    ".hero",
    ".card",
    ".stat-card",
    ".section",
    ".item-card",
    ".feedback-card",
    ".chat-card",
    ".filter-card",
    ".send-card",
    ".login-container",
    ".register-container",
    ".action-box",
    ".info-box",
    ".count-box",
  ].join(",");

  const revealItems = [...document.querySelectorAll(revealSelector)];
  revealItems.forEach((item, index) => {
    item.dataset.uiReveal = reduceMotion ? "visible" : "pending";
    item.style.setProperty("--ui-delay", `${Math.min(index % 6, 5) * 55}ms`);
  });

  if (!reduceMotion && "IntersectionObserver" in window) {
    const observer = new IntersectionObserver(
      (entries, instance) => {
        entries.forEach((entry) => {
          if (!entry.isIntersecting) return;
          entry.target.dataset.uiReveal = "visible";
          instance.unobserve(entry.target);
        });
      },
      { threshold: 0.08, rootMargin: "0px 0px -24px 0px" },
    );

    revealItems.forEach((item) => observer.observe(item));
  } else {
    revealItems.forEach((item) => {
      item.dataset.uiReveal = "visible";
    });
  }

  const interactiveSelector = [
    "button:not(.toggle-btn)",
    "input[type='submit']",
    "input[type='button']",
    ".btn",
    ".btn-primary",
    ".btn-secondary",
    ".btn-light",
    ".btn-chat",
    ".send-btn",
    ".action-link",
    ".feedback-link",
    ".chat-link",
    ".proof-link",
    ".logout",
  ].join(",");

  if (!reduceMotion) {
    document.querySelectorAll(interactiveSelector).forEach((element) => {
      if (element instanceof HTMLInputElement) return;

      element.addEventListener("pointerdown", (event) => {
        const rect = element.getBoundingClientRect();
        const ripple = document.createElement("span");
        ripple.className = "ui-ripple";
        ripple.style.left = `${event.clientX - rect.left}px`;
        ripple.style.top = `${event.clientY - rect.top}px`;
        element.appendChild(ripple);
        ripple.addEventListener("animationend", () => ripple.remove(), {
          once: true,
        });
      });
    });
  }

  document.querySelectorAll("form").forEach((form) => {
    form.addEventListener("submit", () => {
      const submitter = form.querySelector(
        "button[type='submit'], input[type='submit']",
      );
      if (submitter) {
        submitter.classList.add("is-submitting");
        submitter.setAttribute("aria-busy", "true");
      }
    });
  });

  if (!reduceMotion) {
    document.querySelectorAll(".stat-value, .num").forEach((node) => {
      const raw = node.textContent.trim();
      if (!/^\d+$/.test(raw)) return;

      const target = Number.parseInt(raw, 10);
      if (!Number.isFinite(target) || target > 99999) return;

      const start = performance.now();
      const duration = 700;
      node.classList.add("ui-counting");

      const tick = (now) => {
        const progressValue = Math.min((now - start) / duration, 1);
        const eased = 1 - Math.pow(1 - progressValue, 3);
        node.textContent = Math.round(target * eased).toString();

        if (progressValue < 1) {
          requestAnimationFrame(tick);
        } else {
          node.textContent = raw;
          node.classList.remove("ui-counting");
        }
      };

      requestAnimationFrame(tick);
    });
  }
});
