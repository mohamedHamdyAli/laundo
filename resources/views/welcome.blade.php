@extends('layouts.app')

@section('content')

    <a href="#" class="dashboard-responsive-nav-trigger"><i class="fa fa-reorder"></i> Dashboard Navigation</a>

    <div class="dashboard-nav">
        <div class="dashboard-nav-inner">

            <ul data-submenu-title="Main">
                <li class="active"><a href="dashboard.html"><i class="sl sl-icon-settings"></i> Dashboard</a>
                </li>
                <li><a href="dashboard-messages.html"><i class="sl sl-icon-envelope-open"></i> Messages <span
                                class="nav-tag messages">2</span></a></li>
                <li><a href="dashboard-bookings.html"><i class="fa fa-calendar-check-o"></i> Bookings</a></li>
                <li><a href="dashboard-wallet.html"><i class="sl sl-icon-wallet"></i> Wallet</a></li>
            </ul>

            <ul data-submenu-title="Listings">
                <li><a><i class="sl sl-icon-layers"></i> My Listings</a>
                    <ul>
                        <li><a href="dashboard-my-listings.html">Active <span class="nav-tag green">6</span></a>
                        </li>
                        <li><a href="dashboard-my-listings.html">Pending <span class="nav-tag yellow">1</span></a></li>
                        <li><a href="dashboard-my-listings.html">Expired <span class="nav-tag red">2</span></a>
                        </li>
                    </ul>
                </li>
                <li><a href="dashboard-reviews.html"><i class="sl sl-icon-star"></i> Reviews</a></li>
                <li><a href="dashboard-bookmarks.html"><i class="sl sl-icon-heart"></i> Bookmarks</a></li>
                <li><a href="dashboard-add-listing.html"><i class="sl sl-icon-plus"></i> Add Listing</a></li>
            </ul>

            <ul data-submenu-title="Account">
                <li><a href="dashboard-my-profile.html"><i class="sl sl-icon-user"></i> My Profile</a></li>
                <li><a href="index-2.html"><i class="sl sl-icon-power"></i> Logout</a></li>
            </ul>

        </div>
    </div>
    <div class="dashboard-content">

        <div id="titlebar">
            <div class="row">
                <div class="col-md-12">
                    <h2>Howdy, Tom!</h2>
                    <nav id="breadcrumbs">
                        <ul>
                            <li><a href="#">Home</a></li>
                            <li>Dashboard</li>
                        </ul>
                    </nav>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-12">
                <div class="notification success closeable margin-bottom-30">
                    <p>Your listing <strong>Hotel Govendor</strong> has been approved!</p>
                    <a class="close" href="#"></a>
                </div>
            </div>
        </div>

        <div class="row">

            <div class="col-lg-3 col-md-6">
                <div class="dashboard-stat color-1">
                    <div class="dashboard-stat-content">
                        <h4>6</h4> <span>Active Listings</span>
                    </div>
                    <div class="dashboard-stat-icon"><i class="im im-icon-Map2"></i></div>
                </div>
            </div>

            <div class="col-lg-3 col-md-6">
                <div class="dashboard-stat color-2">
                    <div class="dashboard-stat-content">
                        <h4>726</h4> <span>Total Views</span>
                    </div>
                    <div class="dashboard-stat-icon"><i class="im im-icon-Line-Chart"></i></div>
                </div>
            </div>


            <div class="col-lg-3 col-md-6">
                <div class="dashboard-stat color-3">
                    <div class="dashboard-stat-content">
                        <h4>95</h4> <span>Total Reviews</span>
                    </div>
                    <div class="dashboard-stat-icon"><i class="im im-icon-Add-UserStar"></i></div>
                </div>
            </div>

            <div class="col-lg-3 col-md-6">
                <div class="dashboard-stat color-4">
                    <div class="dashboard-stat-content">
                        <h4>126</h4> <span>Times Bookmarked</span>
                    </div>
                    <div class="dashboard-stat-icon"><i class="im im-icon-Heart"></i></div>
                </div>
            </div>
        </div>


        <div class="row">

            <div class="col-lg-6 col-md-12">
                <div class="dashboard-list-box with-icons margin-top-20">
                    <h4>Recent Activities</h4>
                    
                </div>
            </div>

            <div class="col-lg-6 col-md-12">
                <div class="dashboard-list-box invoices with-icons margin-top-20">
                    <h4>Invoices</h4>

                </div>
            </div>
            <div class="col-md-12">
                <div class="copyrights">© 2019 Listeo. All Rights Reserved.</div>
            </div>
        </div>

    </div>
    @endsection