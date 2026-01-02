$(document).ready(function () {
    $("#searchInput").keyup(function () {
        let query = $(this).val();
        if (query.length > 1) {
            $.ajax({
                url: "search_suggestions.php",
                method: "GET",
                data: { query: query },
                success: function (data) {
                    $("#searchResults").html(data).fadeIn();
                }
            });
        } else {
            $("#searchResults").fadeOut();
        }
    });

    // Hide search results when clicking outside
    $(document).on("click", function (e) {
        if (!$(e.target).closest(".search-form").length) {
            $("#searchResults").fadeOut();
        }
    });
});
