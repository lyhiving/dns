(function () {
  function ga() {
    ! function (e, n, o) {
      var t = e.screen,
        a = encodeURIComponent,
        r = ["tid=UA-134621555-2", "dt=" + a(n.title), "dr=" + a(n.referrer), "ul=" + (o.language || o.browserLanguage), "sd=" + t.colorDepth + "-bit", "sr=" + t.width + "x" + t.height, "vp=" + e.innerWidth + "x" + e.innerHeight, "z=" + +new Date],
        i = "?" + r.join("&");
      e.__beacon_img = new Image, e.__beacon_img.src = document.location.protocol + "//stat.tedx.net/ga.php" + i
    }(window, document, navigator, location);
  }
  if (window.addEventListener) window.addEventListener("load", ga, false);
  else if (window.attachEvent) window.attachEvent("onload", ga);
  else window.onload = ga;
})();
